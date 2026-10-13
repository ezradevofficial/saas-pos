<?php

namespace Modules\Accounting\Ledger;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Accounting\Events\JournalPosted;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;

/**
 * ACC-03: the only writer of journals and their lines.
 *
 * - draft(): a journal (draft or pending) with its lines converted to base
 *   currency at the journal date's rate (or the line's own), so what an
 *   approver sees is exactly what posts.
 * - post(): numbers the journal (NUM-01) and marks it posted, inside one
 *   transaction, after BaseCurrencyLock (CUR-02) and with the period row
 *   shared-locked; the database then checks balance, immutability and the
 *   open period (acc_journal_* triggers), at once rather than at commit.
 * - record(): draft and post together (system journals).
 *
 * Every line is checked here, whoever calls: the account is the company's,
 * postable, active and in the line's currency when it has one; parties,
 * branches and dimensions are the company's (shared parties too).
 */
class JournalPoster
{
    public const NUMBER_TYPE = 'accounting.journal';

    public function __construct(
        private readonly Converter $converter,
        private readonly BaseCurrencyLock $baseLock,
        private readonly Periods $periods,
        private readonly Numbering $numbering,
    ) {}

    /**
     * @param  array{date: CarbonImmutable, kind: string, status?: string, memo?: ?string, reference?: ?string, source_type?: ?string, source_id?: ?string, created_by?: ?string, reverses_journal_id?: ?string, recurring_journal_id?: ?string}  $header
     * @param  list<LineInput>  $lines
     */
    public function draft(Company $company, array $header, array $lines, bool $allowControl = true, bool|int $rounding = false): Journal
    {
        return $this->transaction(function () use ($company, $header, $lines, $allowControl, $rounding) {
            $date = $header['date']->startOfDay();
            $period = $this->periods->forDate($company, $date);
            $base = (string) $company->base_currency;
            $rows = $this->lines($company, $base, $date, $lines, $allowControl, $rounding);

            $journal = Journal::query()->create([
                'company_id' => $company->id,
                'date' => $date->toDateString(),
                'period_id' => $period->id,
                'kind' => $header['kind'],
                'status' => $header['status'] ?? Journal::DRAFT,
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
                'reference' => $header['reference'] ?? null,
                'memo' => $header['memo'] ?? null,
                'base_currency' => $base,
                'total_base_minor' => array_sum(array_column($rows, 'base_debit_minor')),
                'reverses_journal_id' => $header['reverses_journal_id'] ?? null,
                'recurring_journal_id' => $header['recurring_journal_id'] ?? null,
                'created_by' => $header['created_by'] ?? null,
            ]);

            $this->insertLines($journal, $rows);

            return $journal;
        });
    }

    /**
     * Replace a draft's lines and header fields (ACC-04: drafts and rejected journals only).
     *
     * @param  array{date?: CarbonImmutable, memo?: ?string, reference?: ?string}  $header
     * @param  list<LineInput>  $lines
     */
    public function revise(Journal $journal, array $header, array $lines, bool $allowControl = false, bool|int $rounding = false): Journal
    {
        return $this->transaction(function () use ($journal, $header, $lines, $allowControl, $rounding) {
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if (! $journal->isEditable()) {
                throw LedgerError::make('journal_not_editable');
            }

            $company = Company::query()->findOrFail($journal->company_id);
            $date = ($header['date'] ?? $journal->date)->startOfDay();
            $rows = $this->lines($company, (string) $company->base_currency, $date, $lines, $allowControl, $rounding);

            JournalLine::query()->where('journal_id', $journal->id)->delete();
            $journal->fill([
                'date' => $date->toDateString(),
                'period_id' => $this->periods->forDate($company, $date)->id,
                'memo' => array_key_exists('memo', $header) ? $header['memo'] : $journal->memo,
                'reference' => array_key_exists('reference', $header) ? $header['reference'] : $journal->reference,
                'base_currency' => $company->base_currency,
                'total_base_minor' => array_sum(array_column($rows, 'base_debit_minor')),
            ])->save();
            $this->insertLines($journal, $rows);

            return $journal->refresh();
        });
    }

    /** Post a journal whose lines are stored (draft, pending or approved). */
    public function post(Journal $journal, ?User $by): Journal
    {
        try {
            return $this->transaction(function () use ($journal, $by) {
                $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

                if ($journal->isPosted()) {
                    return $journal;
                }

                $company = Company::query()->findOrFail($journal->company_id);
                $base = $this->baseLock->lock($company);

                if ($base !== $journal->base_currency) {
                    throw LedgerError::make('base_currency_changed');
                }

                $period = Period::query()->whereKey($journal->period_id)->sharedLock()->firstOrFail();

                if ($period->isClosed()) {
                    throw LedgerError::make('period_closed', ['date' => $journal->date->toDateString()], 'date');
                }

                $this->assertBalanced($journal);
                $number = $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, at: $journal->date->setTime(12, 0)));

                $journal->forceFill([
                    'status' => Journal::POSTED,
                    'number' => $number->number,
                    'posted_at' => CarbonImmutable::now(),
                    'posted_by' => $by?->id,
                    'post_error' => null,
                ])->save();

                // ACC-03: the deferred balance check runs now, inside this transaction.
                DB::connection(TenantContext::CONNECTION)->statement('set constraints acc_journals_balanced, acc_journal_lines_balanced immediate');
                JournalPosted::dispatch((string) $journal->tenant_id, $journal->id, $journal->company_id, (string) $journal->source_type, $journal->source_id);

                return $journal;
            });
        } catch (QueryException $e) {
            throw self::translate($e);
        }
    }

    /**
     * Draft and post at once (system, reversal, revaluation, closing journals).
     *
     * @param  array{date: CarbonImmutable, kind: string, memo?: ?string, reference?: ?string, source_type?: ?string, source_id?: ?string, created_by?: ?string, reverses_journal_id?: ?string}  $header
     * @param  list<LineInput>  $lines
     */
    public function record(Company $company, array $header, array $lines, ?User $by, bool|int $rounding = false): Journal
    {
        return $this->transaction(function () use ($company, $header, $lines, $by, $rounding) {
            // CUR-02: lock the base before computing base amounts.
            $this->baseLock->lock($company);

            return $this->post($this->draft($company, $header, $lines, allowControl: true, rounding: $rounding), $by);
        });
    }

    /**
     * The rows of $inputs: checked, converted to $base at the date's rate,
     * zero lines dropped, and a rounding line (to the rounding account,
     * described as rounding) for a difference within the tolerance: one
     * minor unit per line when $rounding is true, that many minor units
     * when it is a number. Any larger difference is refused.
     *
     * @param  list<LineInput>  $inputs
     * @return list<array<string, mixed>>
     */
    public function lines(Company $company, string $base, CarbonImmutable $date, array $inputs, bool $allowControl, bool|int $rounding): array
    {
        $accounts = Account::query()->where('company_id', $company->id)
            ->whereKey(array_values(array_unique(array_map(fn (LineInput $line) => $line->accountId, $inputs))))->get()->keyBy('id');
        $this->assertDimensions($company, $inputs);
        $at = CarbonImmutable::parse($date->toDateString(), $company->timezone ?: 'UTC')->endOfDay();
        $rates = [];
        $rows = [];

        foreach ($inputs as $index => $line) {
            $account = $accounts->get($line->accountId) ?? throw LedgerError::make('account_unknown', field: "lines.{$index}.account_id");
            $this->assertAccount($account, $line, $index, $allowControl);
            [$baseAmount, $fx] = $this->toBase($company, $base, $at, $line, $rates, $index);

            if ($line->amount->isZero() && $baseAmount->isZero()) {
                continue;
            }

            $rows[] = $this->row($company, $line, $baseAmount, $fx);
        }

        $difference = array_sum(array_column($rows, 'base_debit_minor')) - array_sum(array_column($rows, 'base_credit_minor'));

        $tolerance = $rounding === true ? max(1, count($rows)) : (is_int($rounding) ? $rounding : 0);

        if ($difference !== 0 && abs($difference) <= $tolerance) {
            $account = AccountingSettings::query()->where('company_id', $company->id)->value('rounding_account_id')
                ?? throw LedgerError::make('setting_missing', ['role' => __('accounting.roles.rounding')]);
            $amount = Money::ofMinor((string) abs($difference), $base);
            $rows[] = $this->row($company, new LineInput($account, $difference > 0 ? LineInput::CREDIT : LineInput::DEBIT, $amount, description: __('accounting.journal.rounding')), $amount, FxSnapshot::identity($base));
            $difference = 0;
        }

        if ($rows === []) {
            throw LedgerError::make('journal_empty', field: 'lines');
        }

        if ($difference !== 0) {
            throw LedgerError::make('journal_unbalanced', ['difference' => (string) abs($difference), 'currency' => $base], 'lines');
        }

        return $rows;
    }

    /** ACC-03: refuse a journal whose stored base amounts do not balance (the trigger is the backstop). */
    private function assertBalanced(Journal $journal): void
    {
        $sums = JournalLine::query()->where('journal_id', $journal->id)
            ->selectRaw('count(*) as lines, coalesce(sum(base_debit_minor), 0) as debits, coalesce(sum(base_credit_minor), 0) as credits')->first();

        if ((int) $sums->lines < 2 || (int) $sums->debits !== (int) $sums->credits || (int) $sums->debits === 0) {
            throw LedgerError::make('journal_unbalanced', ['difference' => (string) abs((int) $sums->debits - (int) $sums->credits), 'currency' => $journal->base_currency], 'lines');
        }
    }

    private function assertAccount(Account $account, LineInput $line, int $index, bool $allowControl): void
    {
        $field = "lines.{$index}.account_id";

        match (true) {
            $account->isArchived() => throw LedgerError::make('account_archived', ['code' => $account->code], $field),
            ! $account->postable => throw LedgerError::make('account_not_postable', ['code' => $account->code], $field),
            $account->is_control && ! $allowControl => throw LedgerError::make('control_account', ['code' => $account->code], $field),
            $account->currency !== null && $account->currency !== $line->amount->currency() => throw LedgerError::make('account_currency', ['code' => $account->code, 'currency' => $account->currency], $field),
            default => null,
        };
    }

    /**
     * @param  array<string, FxSnapshot>  $rates
     * @return array{0: Money, 1: FxSnapshot}
     */
    private function toBase(Company $company, string $base, CarbonImmutable $at, LineInput $line, array &$rates, int $index): array
    {
        $currency = $line->amount->currency();

        if ($currency === $base) {
            return [$line->base ?? $line->amount, FxSnapshot::identity($base)];
        }

        $fx = $line->fx;

        if ($fx === null) {
            try {
                $fx = $rates[$currency] ??= $this->converter->toBase(Money::ofMinor(0, $currency), $company, $at)['snapshot'];
            } catch (RateUnavailable $e) {
                throw LedgerError::make('rate_unavailable', ['pair' => "{$currency}/{$base}", 'date' => $at->toDateString()], "lines.{$index}.currency");
            }
        }

        if (! in_array($currency, [$fx->base(), $fx->quote()], true) || ! in_array($base, [$fx->base(), $fx->quote()], true)) {
            throw new InvalidArgumentException("A {$fx->base()}/{$fx->quote()} rate cannot convert {$currency} to {$base}.");
        }

        if ($line->base !== null) {
            return [$line->base, $fx];
        }

        return [$fx->convert($line->amount), $fx];
    }

    /** @return array<string, mixed> */
    private function row(Company $company, LineInput $line, Money $base, FxSnapshot $fx): array
    {
        $debit = $line->side === LineInput::DEBIT;

        return [
            'company_id' => $company->id,
            'account_id' => $line->accountId,
            'description' => $line->description === null ? null : mb_substr($line->description, 0, 255),
            'currency' => $line->amount->currency(),
            'debit_minor' => $debit ? (int) $line->amount->minor() : 0,
            'credit_minor' => $debit ? 0 : (int) $line->amount->minor(),
            'fx' => $fx,
            'base_debit_minor' => $debit ? (int) $base->minor() : 0,
            'base_credit_minor' => $debit ? 0 : (int) $base->minor(),
            'party_id' => $line->dimension('party_id'),
            'branch_id' => $line->dimension('branch_id'),
            'cost_centre_id' => $line->dimension('cost_centre_id'),
            'department_id' => $line->dimension('department_id'),
            'project_id' => $line->dimension('project_id'),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertLines(Journal $journal, array $rows): void
    {
        foreach (array_values($rows) as $index => $row) {
            JournalLine::query()->create([...$row, 'journal_id' => $journal->id, 'line_no' => $index + 1]);
        }
    }

    /** @param list<LineInput> $inputs */
    private function assertDimensions(Company $company, array $inputs): void
    {
        DimensionCheck::assert($company, array_map(fn (LineInput $line) => array_combine(DimensionCheck::KEYS, array_map(fn (string $key) => $line->dimension($key), DimensionCheck::KEYS)), array_values($inputs)));
    }

    /** The database's refusals in the module's words (the triggers are the last line of defence). */
    public static function translate(QueryException $e): \Throwable
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'acc_journal_unbalanced') => LedgerError::make('journal_unbalanced', ['difference' => '?', 'currency' => ''], 'lines'),
            str_contains($message, 'acc_period_closed') => LedgerError::make('period_closed', ['date' => ''], 'date'),
            str_contains($message, 'acc_journal_immutable') => LedgerError::make('journal_posted'),
            default => $e,
        };
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
