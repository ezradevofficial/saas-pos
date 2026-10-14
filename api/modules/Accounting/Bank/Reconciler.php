<?php

namespace Modules\Accounting\Bank;

use App\Core\Audit\Auditor;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\BankMatch;
use Modules\Accounting\Models\BankRule;
use Modules\Accounting\Models\BankStatement;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * ACC-13, ACC-14: statements and reconciliation of one bank account.
 *
 * - import(): a statement's lines, skipping those already imported for the
 *   account (same date, amount and reference, or description when there
 *   is no reference: the fingerprint, unique per account).
 * - The ledger side is the posted lines of the account's GL account, in
 *   its currency (debit − credit), not yet matched.
 * - match(): one statement line against one or more ledger lines of the
 *   same total; unmatch() undoes it (the match rows are kept, closed).
 * - Suggestions: ledger lines of the same amount within SUGGEST_DAYS of
 *   the statement date, a reference found in the line first.
 * - fromLine(): a journal for a statement line with no ledger line (bank
 *   charges, M-Pesa fees), posted to a chosen account and matched; rules
 *   do it for lines whose description contains their text.
 */
class Reconciler
{
    public const SUGGEST_DAYS = 7;

    public const SOURCE = 'accounting.bank_line';

    public function __construct(
        private readonly StatementParser $parser,
        private readonly JournalPoster $poster,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, ?string> $mapping */
    public function import(BankAccount $bank, UploadedFile $file, string $format, array $mapping, User $by): BankStatement
    {
        $lines = $this->parser->parse($file->getRealPath(), $format, $bank->currency, $mapping);

        return $this->transaction(function () use ($bank, $file, $format, $lines, $by) {
            $statement = BankStatement::query()->create([
                'company_id' => $bank->company_id,
                'bank_account_id' => $bank->id,
                'format' => $format,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'imported_by' => $by->id,
            ]);
            $added = 0;
            $skipped = 0;

            $seen = [];

            foreach ($lines as $line) {
                // The same date, amount and reference twice in one file are two lines (two equal fees);
                // the occurrence number keeps them apart, and the same file again matches them one for one.
                $key = implode('|', [$line['date'], $line['amount_minor'], mb_strtolower($line['reference'] ?? $line['description'])]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $fingerprint = hash('sha256', $key.'#'.$seen[$key]);

                if (BankStatementLine::query()->where('bank_account_id', $bank->id)->where('fingerprint', $fingerprint)->exists()) {
                    $skipped++;

                    continue;
                }

                BankStatementLine::query()->create([...$line, 'company_id' => $bank->company_id, 'bank_account_id' => $bank->id, 'statement_id' => $statement->id,
                    'currency' => $bank->currency, 'fingerprint' => $fingerprint, 'status' => BankStatementLine::UNMATCHED]);
                $added++;
            }

            $dates = array_column($lines, 'date');
            $statement->fill(['line_count' => $added, 'skipped_count' => $skipped, 'starts_on' => $dates === [] ? null : min($dates), 'ends_on' => $dates === [] ? null : max($dates)])->save();
            $this->auditor->record('accounting.bank_statement.import', $statement, null, ['lines' => $added, 'skipped' => $skipped, 'file' => $statement->file_name]);

            return $statement;
        });
    }

    /** Posted ledger lines of the bank's GL account not matched yet. */
    public function unmatchedLedger(BankAccount $bank): Collection
    {
        return JournalLine::query()
            ->select('acc_journal_lines.*')
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.status', Journal::POSTED)
            ->where('acc_journal_lines.account_id', $bank->account_id)
            // Only lines in the account's currency are on the statement.
            ->where('acc_journal_lines.currency', $bank->currency)
            ->where(fn ($q) => $q->where('acc_journal_lines.debit_minor', '>', 0)->orWhere('acc_journal_lines.credit_minor', '>', 0))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('acc_bank_matches')->whereColumn('acc_bank_matches.journal_line_id', 'acc_journal_lines.id')->whereNull('acc_bank_matches.unmatched_at'))
            ->with('journal:id,number,date,reference,memo,source_type,source_id')
            ->orderBy('acc_journals.date')->orderBy('acc_journal_lines.line_no')
            ->get();
    }

    /** @return array<string, list<string>> statement line id => suggested ledger line ids */
    public function suggestions(BankAccount $bank, Collection $statementLines, Collection $ledger): array
    {
        $taken = [];
        $suggested = [];

        foreach ($statementLines as $line) {
            $candidates = $ledger->filter(fn (JournalLine $l) => ! isset($taken[$l->id])
                && $l->debit_minor - $l->credit_minor === $line->amount_minor
                && abs($l->journal->date->diffInDays($line->date)) <= self::SUGGEST_DAYS);

            if ($candidates->isEmpty()) {
                continue;
            }

            $reference = mb_strtolower((string) $line->reference.' '.$line->description);
            $best = $candidates->sortBy(fn (JournalLine $l) => [
                ($l->journal->reference !== null && $l->journal->reference !== '' && str_contains($reference, mb_strtolower($l->journal->reference))) ? 0 : 1,
                abs($l->journal->date->diffInDays($line->date)),
            ])->first();
            $taken[$best->id] = true;
            $suggested[$line->id] = [$best->id];
        }

        return $suggested;
    }

    /** @param list<string> $journalLineIds */
    public function match(BankStatementLine $line, array $journalLineIds, User $by): BankStatementLine
    {
        return $this->transaction(function () use ($line, $journalLineIds, $by) {
            $line = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->status !== BankStatementLine::UNMATCHED) {
                throw LedgerError::make('statement_line_matched');
            }

            $bank = BankAccount::query()->findOrFail($line->bank_account_id);
            $ledger = $this->unmatchedLedger($bank)->whereIn('id', $journalLineIds);

            if ($journalLineIds === [] || $ledger->count() !== count(array_unique($journalLineIds))) {
                throw LedgerError::make('match_lines_unavailable', field: 'journal_line_ids');
            }

            $total = $ledger->sum(fn (JournalLine $l) => $l->debit_minor - $l->credit_minor);

            if ($total !== $line->amount_minor) {
                throw LedgerError::make('match_amount', field: 'journal_line_ids');
            }

            foreach ($ledger as $ledgerLine) {
                BankMatch::query()->create([
                    'company_id' => $line->company_id,
                    'statement_line_id' => $line->id,
                    'journal_line_id' => $ledgerLine->id,
                    'amount_minor' => $ledgerLine->debit_minor - $ledgerLine->credit_minor,
                    'matched_by' => $by->id,
                ]);
            }

            $line->fill(['status' => BankStatementLine::MATCHED, 'matched_at' => CarbonImmutable::now(), 'matched_by' => $by->id])->save();
            $this->auditor->record('accounting.bank_statement_line.match', $line, null, ['journal_lines' => array_values($journalLineIds)]);

            return $line;
        });
    }

    public function unmatch(BankStatementLine $line, User $by): BankStatementLine
    {
        return $this->transaction(function () use ($line, $by) {
            $line = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->status !== BankStatementLine::MATCHED) {
                throw LedgerError::make('statement_line_unmatched');
            }

            BankMatch::query()->where('statement_line_id', $line->id)->whereNull('unmatched_at')->get()
                ->each(fn (BankMatch $match) => $match->fill(['unmatched_at' => CarbonImmutable::now(), 'unmatched_by' => $by->id])->save());
            $line->fill(['status' => BankStatementLine::UNMATCHED, 'matched_at' => null, 'matched_by' => null])->save();
            $this->auditor->record('accounting.bank_statement_line.unmatch', $line);

            return $line;
        });
    }

    /** Post a journal for the line to $accountId (the other side is the bank) and match it. */
    public function fromLine(BankStatementLine $line, string $accountId, ?string $costCentreId, ?string $description, User $by): BankStatementLine
    {
        return $this->transaction(function () use ($line, $accountId, $costCentreId, $description, $by) {
            $line = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->status !== BankStatementLine::UNMATCHED) {
                throw LedgerError::make('statement_line_matched');
            }

            $bank = BankAccount::query()->findOrFail($line->bank_account_id);
            // Posted once already (then unmatched): its journal is matched again, never posted twice.
            $earlier = Journal::query()->where('company_id', $bank->company_id)->where('source_type', self::SOURCE)->where('source_id', $line->id)->value('id');

            if ($earlier !== null) {
                return $this->match($line, [JournalLine::query()->where('journal_id', $earlier)->where('account_id', $bank->account_id)->value('id')], $by);
            }

            $company = Company::query()->findOrFail($bank->company_id);
            $account = Account::query()->where('company_id', $company->id)->find($accountId);

            if ($account === null || $account->is_control || $account->id === $bank->account_id) {
                throw LedgerError::make('document_account', field: 'account_id');
            }

            $amount = Money::ofMinor((string) abs($line->amount_minor), $bank->currency);
            $in = $line->amount_minor > 0;
            $text = $description ?? $line->description;
            $journal = $this->poster->record($company, [
                'date' => $line->date,
                'kind' => 'system',
                'source_type' => self::SOURCE,
                'source_id' => $line->id,
                'reference' => $line->reference === null ? null : mb_substr($line->reference, 0, 80),
                'memo' => $text,
                'created_by' => $by->id,
            ], [
                new LineInput($bank->account_id, $in ? LineInput::DEBIT : LineInput::CREDIT, $amount, null, null, $text),
                new LineInput($account->id, $in ? LineInput::CREDIT : LineInput::DEBIT, $amount, null, null, $text, ['cost_centre_id' => $costCentreId]),
            ], $by);

            $bankLine = JournalLine::query()->where('journal_id', $journal->id)->where('account_id', $bank->account_id)->value('id');

            return $this->match($line, [$bankLine], $by);
        });
    }

    /**
     * Match what is certain: a statement line with exactly one ledger line
     * of its amount in the window; else the first active rule that applies.
     *
     * @return array{matched: int, ruled: int}
     */
    public function autoMatch(BankAccount $bank, User $by): array
    {
        $matched = 0;
        $ruled = 0;
        $rules = BankRule::query()->where('company_id', $bank->company_id)->whereNull('archived_at')->orderBy('created_at')->get();

        foreach (BankStatementLine::query()->where('bank_account_id', $bank->id)->where('status', BankStatementLine::UNMATCHED)->orderBy('date')->get() as $line) {
            $ledger = $this->unmatchedLedger($bank)->filter(fn (JournalLine $l) => $l->debit_minor - $l->credit_minor === $line->amount_minor
                && abs($l->journal->date->diffInDays($line->date)) <= self::SUGGEST_DAYS);

            if ($ledger->count() === 1) {
                $this->match($line, [$ledger->first()->id], $by);
                $matched++;

                continue;
            }

            $rule = $rules->first(fn (BankRule $rule) => $rule->applies($line));

            if ($ledger->isEmpty() && $rule !== null) {
                $this->fromLine($line, $rule->account_id, $rule->cost_centre_id, $rule->name, $by);
                $ruled++;
            }
        }

        return ['matched' => $matched, 'ruled' => $ruled];
    }

    /** @return array{statement_balance: int, ledger_balance: int, unmatched_statement: int, unmatched_ledger: int} in the account's currency */
    public function summary(BankAccount $bank): array
    {
        $statement = (int) BankStatementLine::query()->where('bank_account_id', $bank->id)->sum('amount_minor');
        $ledger = (int) JournalLine::query()->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.status', Journal::POSTED)->where('acc_journal_lines.account_id', $bank->account_id)
            ->where('acc_journal_lines.currency', $bank->currency)
            ->sum(DB::raw('acc_journal_lines.debit_minor - acc_journal_lines.credit_minor'));

        return [
            'statement_balance' => $statement,
            'ledger_balance' => $ledger,
            'unmatched_statement' => (int) BankStatementLine::query()->where('bank_account_id', $bank->id)->where('status', BankStatementLine::UNMATCHED)->sum('amount_minor'),
            'unmatched_ledger' => (int) $this->unmatchedLedger($bank)->sum(fn (JournalLine $l) => $l->debit_minor - $l->credit_minor),
        ];
    }

    /** One transaction; a race lost on a unique key (a match, a line, a source) is a 409 to retry, never a 500. */
    private function transaction(callable $fn): mixed
    {
        try {
            return DB::connection(TenantContext::CONNECTION)->transaction($fn);
        } catch (UniqueConstraintViolationException) {
            throw LedgerError::make('reconcile_conflict', status: 409);
        }
    }
}
