<?php

namespace Modules\Accounting\Imports;

use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Http\Requests\OpeningBalanceRequest;
use Modules\Accounting\Http\Requests\ReverseJournalRequest;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Ledger\Reversals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;

/**
 * ACC-20, IMP-01: opening balances, one account per row (company name,
 * date, account code, a debit or a credit, optionally another currency
 * with its rate). Each row posts an opening journal: the account against
 * the suspense account, so the suspense account is back to zero once
 * every balance is in. Rows pass OpeningBalanceRequest
 * (`accounting.journal.create` and `accounting.journal.approve` or `accounting.account.manage` at the company; dated on or before the company's first other posting). Control accounts are
 * refused: open invoices come in as documents, so the subledgers agree
 * with the ledger. Undo reverses each row's journal.
 */
class OpeningBalancesImporter extends Importer
{
    public const SOURCE = 'accounting.opening';

    public function key(): string
    {
        return 'opening_balances';
    }

    public function model(): string
    {
        return Journal::class;
    }

    public function permissions(string $mode): array
    {
        return ['accounting.journal.create'];
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('company', true),
            ImportColumn::of('date', 'date', true),
            ImportColumn::text('account_code', true),
            ImportColumn::of('debit', 'decimal'),
            ImportColumn::of('credit', 'decimal'),
            ImportColumn::text('currency'),
            ImportColumn::of('rate', 'decimal'),
            ImportColumn::text('reference'),
        ];
    }

    public function example(): array
    {
        return ['company' => 'Amani Retail', 'date' => '2026-01-01', 'account_code' => '1120', 'debit' => '250000.00', 'credit' => '', 'currency' => '', 'rate' => '', 'reference' => 'Opening'];
    }

    protected function fieldColumns(): array
    {
        return ['date' => 'date', 'account_id' => 'account_code', 'debit' => 'debit', 'credit' => 'credit', 'currency' => 'currency', 'rate' => 'rate', 'reference' => 'reference'];
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $companies = $context->remember('companies', fn () => Company::query()->get()->keyBy(fn (Company $company) => Str::lower($company->name))->all());
        $company = $companies[Str::lower($values['company'] ?? '')] ?? throw new RowRejected(['company' => [__('imports.errors.unknown', ['value' => $values['company'] ?? ''])]]);
        $account = Account::query()->where('company_id', $company->id)->where('code', $values['account_code'] ?? '')->first()
            ?? throw new RowRejected(['account_code' => [__('imports.errors.unknown', ['value' => $values['account_code'] ?? ''])]]);

        if ($account->is_control) {
            throw new RowRejected(['account_code' => [__('accounting.imports.control_account', ['code' => $account->code])]]);
        }

        return new PreparedRow($number, array_filter([
            'date' => $values['date'] ?? null,
            'account_id' => $account->id,
            'debit' => $values['debit'] ?? null,
            'credit' => $values['credit'] ?? null,
            'currency' => isset($values['currency']) ? Str::upper($values['currency']) : null,
            'rate' => $values['rate'] ?? null,
            'reference' => $values['reference'] ?? null,
        ], fn ($value) => $value !== null), null, ['company' => $company]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = app(DomainRequests::class)->validate(OpeningBalanceRequest::class, $row->input, $context->user, ['company' => $row->context['company']]);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        /** @var Company $company */
        $company = $row->context['company'];
        $data = $row->validated;
        [$line] = app(LineParser::class)->parse($company, [[
            'account_id' => $data['account_id'], 'debit' => $data['debit'] ?? null, 'credit' => $data['credit'] ?? null,
            'currency' => $data['currency'] ?? $company->base_currency, 'rate' => $data['rate'] ?? null, 'description' => $data['reference'] ?? null,
        ]]);
        $poster = app(JournalPoster::class);
        $date = CarbonImmutable::parse($data['date']);
        $suspense = app(Charts::class)->accountFor($company->id, 'suspense');

        $journal = $poster->record($company, [
            'date' => $date,
            'kind' => 'opening',
            'source_type' => self::SOURCE,
            'source_id' => (string) Str::uuid7(),
            'reference' => isset($data['reference']) ? mb_substr($data['reference'], 0, 80) : null,
            'memo' => __('accounting.imports.opening_memo'),
            'created_by' => $context->user->id,
        ], [$line, new LineInput($suspense, $line->side === LineInput::DEBIT ? LineInput::CREDIT : LineInput::DEBIT, $line->amount, $line->fx)], $context->user);

        return ['record' => $journal, 'before' => null];
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $journal = Journal::query()->find($row->record_id);

        if ($journal === null || $journal->reversed_by_journal_id !== null) {
            return;
        }

        // As POST accounting/journals/{id}/reverse: `accounting.journal.reverse` at the journal's company.
        app(DomainRequests::class)->validate(ReverseJournalRequest::class, [], $context->user, ['acc_journal' => $journal]);
        app(Reversals::class)->reverse($journal, null, $context->user);
    }

    public function companiesOf(array $ids): array
    {
        return Journal::query()->whereKey($ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['accounting.journal.view'];
    }

    public function describe(Model $record): string
    {
        return (string) ($record->number ?? $record->id);
    }
}
