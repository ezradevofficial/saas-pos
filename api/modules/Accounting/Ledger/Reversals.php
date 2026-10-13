<?php

namespace Modules\Accounting\Ledger;

use App\Core\Audit\Auditor;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * ACC-05: a posted journal is corrected only by its reversal: a new
 * journal with each line's sides swapped at the same transaction and base
 * amounts and rates, dated in an open period, linked both ways. Once only
 * (the reversal's source key is the original's id, unique).
 */
class Reversals
{
    public const SOURCE = 'accounting.reversal';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly Auditor $auditor,
    ) {}

    public function reverse(Journal $journal, ?CarbonImmutable $date, ?User $by, ?string $memo = null): Journal
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($journal, $date, $by, $memo) {
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if (! $journal->isPosted()) {
                throw LedgerError::make('journal_not_posted');
            }

            if ($journal->reversed_by_journal_id !== null || $journal->kind === 'reversal') {
                throw LedgerError::make('journal_reversed');
            }

            $company = Company::query()->findOrFail($journal->company_id);
            $lines = JournalLine::query()->where('journal_id', $journal->id)->orderBy('line_no')->get()->map(fn (JournalLine $line) => new LineInput(
                $line->account_id,
                $line->debit_minor > 0 || $line->base_debit_minor > 0 ? LineInput::CREDIT : LineInput::DEBIT,
                Money::ofMinor((string) ($line->debit_minor + $line->credit_minor), $line->currency),
                $line->fx,
                Money::ofMinor((string) ($line->base_debit_minor + $line->base_credit_minor), $journal->base_currency),
                $line->description,
                $line->only(['party_id', 'branch_id', 'cost_centre_id', 'department_id', 'project_id']),
            ))->all();

            $reversal = $this->poster->record($company, [
                'date' => $date ?? $journal->date,
                'kind' => 'reversal',
                'source_type' => self::SOURCE,
                'source_id' => $journal->id,
                'reverses_journal_id' => $journal->id,
                'reference' => $journal->number,
                'memo' => $memo ?? __('accounting.journal.reversal_of', ['number' => $journal->number]),
                'created_by' => $by?->id,
            ], $lines, $by);

            $journal->forceFill(['reversed_by_journal_id' => $reversal->id])->save();
            $this->auditor->record('accounting.journal.reverse', $journal, null, ['reversal' => $reversal->id, 'number' => $reversal->number]);

            return $reversal;
        });
    }
}
