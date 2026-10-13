<?php

namespace Modules\Accounting\Ledger;

use App\Core\Audit\Auditor;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Events\PeriodClosed;
use Modules\Accounting\Fx\Revaluer;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\Revaluation;

/**
 * ACC-08: closing and reopening periods.
 *
 * - close(): closes the period and every earlier open period of the
 *   company, oldest first (periods close in order). Refused while any
 *   journal or document dated in them is still a draft, pending or
 *   approved-but-unposted. Optionally revalues first (ACC-15). Closing a
 *   fiscal year's last period posts the closing entry: every income and
 *   expense balance of the year to retained earnings. Closed, the database
 *   refuses any posting dated in it (acc_journal_period_open).
 * - reopen(): only the latest closed period, with a reason, audited;
 *   reopening a year-end period reverses its closing entry.
 */
class PeriodClose
{
    public const CLOSING_SOURCE = 'accounting.closing';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly Reversals $reversals,
        private readonly Revaluer $revaluer,
        private readonly Charts $charts,
        private readonly Auditor $auditor,
    ) {}

    public function close(Period $period, User $by, bool $revalue = false): Period
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($period, $by, $revalue) {
            $periods = Period::query()->where('company_id', $period->company_id)->where('status', Period::OPEN)
                ->where('starts_on', '<=', $period->starts_on->toDateString())->orderBy('starts_on')->lockForUpdate()->get();
            $target = $periods->firstWhere('id', $period->id) ?? throw LedgerError::make('period_closed', ['date' => $period->ends_on->toDateString()]);
            $from = $periods->first()->starts_on->toDateString();
            $to = $target->ends_on->toDateString();
            $this->assertNothingPending($period->company_id, $from, $to);

            if ($revalue && ! Revaluation::query()->where('period_id', $target->id)->exists()) {
                $this->revaluer->revalue($target, $by);
            }

            foreach ($periods as $open) {
                if ($open->number === 12) {
                    $open->closing_journal_id = $this->closingEntry($open, $by);
                }

                $open->fill(['status' => Period::CLOSED, 'closed_at' => CarbonImmutable::now(), 'closed_by' => $by->id])->save();
                $this->auditor->record('accounting.period.close', $open, null, ['starts_on' => $open->starts_on->toDateString()]);
                PeriodClosed::dispatch((string) $open->tenant_id, $open->id, $open->company_id);
            }

            return $target->refresh();
        });
    }

    public function reopen(Period $period, User $by, string $reason): Period
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($period, $by, $reason) {
            $period = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! $period->isClosed()) {
                throw LedgerError::make('period_open');
            }

            if (Period::query()->where('company_id', $period->company_id)->where('status', Period::CLOSED)->where('starts_on', '>', $period->starts_on->toDateString())->exists()) {
                throw LedgerError::make('period_reopen_order');
            }

            $period->fill(['status' => Period::OPEN, 'closed_at' => null, 'closed_by' => null, 'reopened_at' => CarbonImmutable::now(), 'reopened_by' => $by->id, 'reopen_reason' => $reason])->save();

            if ($period->closing_journal_id !== null) {
                $closing = Journal::query()->findOrFail($period->closing_journal_id);
                $this->reversals->reverse($closing, $period->ends_on, $by, internal: true);
                $period->fill(['closing_journal_id' => null])->save();
            }

            $this->auditor->record('accounting.period.reopen', $period, null, ['reason' => $reason, 'starts_on' => $period->starts_on->toDateString()]);

            return $period->refresh();
        });
    }

    private function assertNothingPending(string $companyId, string $from, string $to): void
    {
        $journals = Journal::query()->where('company_id', $companyId)->whereBetween('date', [$from, $to])
            ->whereIn('status', [Journal::DRAFT, Journal::PENDING, Journal::APPROVED])->count();
        $documents = Document::query()->where('company_id', $companyId)->whereBetween('issue_date', [$from, $to])->where('status', Document::DRAFT)->count();

        if ($journals + $documents > 0) {
            throw LedgerError::make('period_has_drafts', ['journals' => (string) $journals, 'documents' => (string) $documents]);
        }
    }

    /** The year's income and expense balances to retained earnings, dated the year's last day; null when there are none. */
    private function closingEntry(Period $last, User $by): ?string
    {
        $company = Company::query()->findOrFail($last->company_id);
        $start = Period::query()->where('company_id', $company->id)->where('fiscal_year', $last->fiscal_year)->min('starts_on');
        $balances = JournalLine::query()->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->where('acc_journals.company_id', $company->id)->where('acc_journals.status', Journal::POSTED)
            ->whereBetween('acc_journals.date', [$start, $last->ends_on->toDateString()])
            ->whereIn('acc_accounts.type', ['income', 'expense'])
            ->groupBy('acc_journal_lines.account_id')
            ->selectRaw('acc_journal_lines.account_id, sum(acc_journal_lines.base_debit_minor - acc_journal_lines.base_credit_minor) as balance')
            ->get();
        $base = (string) $company->base_currency;
        $lines = [];
        $net = 0;

        foreach ($balances as $row) {
            $balance = (int) $row->balance;

            if ($balance === 0) {
                continue;
            }

            $net += $balance;
            $lines[] = new LineInput($row->account_id, $balance > 0 ? LineInput::CREDIT : LineInput::DEBIT, Money::ofMinor((string) abs($balance), $base));
        }

        if ($lines === []) {
            return null;
        }

        if ($net !== 0) {
            $lines[] = new LineInput($this->charts->accountFor($company->id, 'retained_earnings'), $net > 0 ? LineInput::DEBIT : LineInput::CREDIT, Money::ofMinor((string) abs($net), $base));
        }

        return $this->poster->record($company, [
            'date' => $last->ends_on,
            'kind' => 'closing',
            'source_type' => self::CLOSING_SOURCE,
            'source_id' => (string) Str::uuid7(),
            'memo' => __('accounting.periods.closing_memo', ['year' => (string) $last->fiscal_year]),
            'created_by' => $by->id,
        ], $lines, $by)->id;
    }
}
