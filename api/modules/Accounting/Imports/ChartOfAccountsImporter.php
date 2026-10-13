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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Http\Requests\StoreAccountRequest;
use Modules\Accounting\Http\Requests\UpdateAccountRequest;
use Modules\Accounting\Models\Account;

/**
 * ACC-20, IMP-01: accounts of a company's chart (by company name), each
 * row through the API's own Form Request (`accounting.account.manage` at
 * the company). A parent is named by its code: an account of the company
 * or one of an earlier row. Update mode matches on company and code; an
 * account with postings keeps its type, currency and postable flag. Undo
 * archives what was created and puts back what was changed.
 */
class ChartOfAccountsImporter extends Importer
{
    private const FIELDS = ['code', 'name', 'type', 'subtype', 'parent_id', 'postable', 'currency', 'cash_flow'];

    public function key(): string
    {
        return 'chart_of_accounts';
    }

    public function model(): string
    {
        return Account::class;
    }

    public function permissions(string $mode): array
    {
        return ['accounting.account.manage'];
    }

    public function matchColumns(): array
    {
        return ['company', 'code'];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('company', true),
            ImportColumn::text('code', true),
            ImportColumn::text('name', true),
            ImportColumn::enum('type', Account::TYPES, 'accounting.account_types', true),
            ImportColumn::enum('subtype', Account::SUBTYPES, 'accounting.subtypes', true),
            ImportColumn::text('parent_code'),
            ImportColumn::of('postable', 'boolean'),
            ImportColumn::text('currency'),
            ImportColumn::enum('cash_flow', Account::CASH_FLOWS, 'accounting.cash_flows'),
        ];
    }

    public function example(): array
    {
        return ['company' => 'Amani Retail', 'code' => '6450', 'name' => 'Internet', 'type' => 'expense', 'subtype' => 'expense', 'parent_code' => '6000', 'postable' => 'yes', 'currency' => '', 'cash_flow' => ''];
    }

    protected function fieldColumns(): array
    {
        return ['code' => 'code', 'name' => 'name', 'type' => 'type', 'subtype' => 'subtype', 'parent_id' => 'parent_code', 'postable' => 'postable', 'currency' => 'currency', 'cash_flow' => 'cash_flow'];
    }

    public function identity(array $values): ?string
    {
        return Str::lower(($values['company'] ?? '').'|'.($values['code'] ?? ''));
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $company = $this->company($values['company'] ?? '', $context);
        $existing = Account::query()->where('company_id', $company->id)->where('code', $values['code'] ?? '')->first();

        if ($existing !== null && ! $context->updates()) {
            throw new RowRejected(['code' => [__('accounting.imports.account_exists', ['code' => (string) $values['code']])]]);
        }

        $input = array_filter([
            'code' => $values['code'] ?? null,
            'name' => $values['name'] ?? null,
            'type' => $values['type'] ?? null,
            'subtype' => $values['subtype'] ?? null,
            'postable' => $values['postable'] ?? null,
            'currency' => isset($values['currency']) ? Str::upper($values['currency']) : null,
            'cash_flow' => $values['cash_flow'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($values['parent_code'])) {
            $input['parent_id'] = Account::query()->where('company_id', $company->id)->where('code', $values['parent_code'])->value('id')
                ?? throw new RowRejected(['parent_code' => [__('imports.errors.unknown', ['value' => $values['parent_code']])]]);
        }

        return new PreparedRow($number, $input, $existing, ['company' => $company]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = $row->record === null
            ? app(DomainRequests::class)->validate(StoreAccountRequest::class, $row->input, $context->user, ['company' => $row->context['company']])
            : app(DomainRequests::class)->validate(UpdateAccountRequest::class, $row->input, $context->user, ['acc_account' => $row->record]);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $charts = app(Charts::class);

        if ($row->record === null) {
            $account = new Account([...$row->validated, 'company_id' => $row->context['company']->id]);
            $charts->assertParent($account, $account->parent_id);
            $account->save();

            return ['record' => $account, 'before' => null];
        }

        /** @var Account $account */
        $account = $row->record;
        $before = $account->only(self::FIELDS);
        $account->fill($row->validated);

        if ($charts->hasPostings($account) && $account->isDirty(['type', 'currency', 'postable'])) {
            throw new RowRejected(['type' => [__('accounting.errors.account_has_postings', ['code' => $account->code])]]);
        }

        $charts->assertParent($account, $account->parent_id);
        $account->save();

        return ['record' => $account, 'before' => $before];
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $account = Account::query()->find($row->record_id);

        if ($account === null) {
            return;
        }

        app(DomainRequests::class)->validate(UpdateAccountRequest::class, [], $context->user, ['acc_account' => $account]);

        if ($row->action === 'created') {
            $account->isArchived() || $account->archive();

            return;
        }

        $account->fill(array_intersect_key((array) $row->before, array_flip(self::FIELDS)))->save();
    }

    public function companiesOf(array $ids): array
    {
        return Account::query()->whereKey($ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['accounting.account.view', 'accounting.account.manage'];
    }

    public function describe(Model $record): string
    {
        return trim($record->code.' '.$record->name);
    }

    private function company(string $name, ImportContext $context): Company
    {
        $companies = $context->remember('companies', fn () => Company::query()->get(['id', 'name', 'tenant_id', 'base_currency', 'country', 'timezone', 'fiscal_year_start_month'])
            ->keyBy(fn (Company $company) => Str::lower($company->name))->all());

        return $companies[Str::lower($name)] ?? throw new RowRejected(['company' => [__('imports.errors.unknown', ['value' => $name])]]);
    }
}
