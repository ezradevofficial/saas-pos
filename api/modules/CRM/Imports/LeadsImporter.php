<?php

namespace Modules\CRM\Imports;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\Http\Requests\LeadRequest;
use Modules\CRM\Leads\Leads;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Option;
use Throwable;

/**
 * IMP-01, CRM-07: leads from a file. Company and branch by name, source
 * by name (the company's sources), owner by email; the estimated value in
 * major units of the currency (the company's base currency by default).
 * Every row passes the API's own Form Request (LeadRequest: the create
 * permission at the branch, field rules, custom fields) and the writer;
 * leads without an owner are dealt by the assignment rules (CRM-11),
 * channel `import`. Create only (a lead has no natural key to match).
 * Undo archives the created lead.
 */
class LeadsImporter extends Importer
{
    public function key(): string
    {
        return 'crm_leads';
    }

    public function model(): string
    {
        return Lead::class;
    }

    public function customFieldEntity(): ?string
    {
        return LeadEntity::KEY;
    }

    public function permissions(string $mode): array
    {
        return ['crm.lead.create'];
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('company', true),
            ImportColumn::text('branch', true),
            ImportColumn::text('name', true),
            ImportColumn::text('organisation'),
            ImportColumn::text('job_title'),
            ImportColumn::text('email'),
            ImportColumn::text('phone'),
            ImportColumn::text('source'),
            ImportColumn::text('owner_email'),
            ImportColumn::of('estimated_value', 'decimal'),
            ImportColumn::text('currency'),
            ImportColumn::text('notes'),
        ];
    }

    protected function fieldColumns(): array
    {
        return [
            'branch_id' => 'branch', 'name' => 'name', 'organisation' => 'organisation', 'title' => 'job_title', 'email' => 'email', 'phone' => 'phone',
            'source_id' => 'source', 'owner_id' => 'owner_email', 'estimated_value_minor' => 'estimated_value', 'currency' => 'currency', 'notes' => 'notes',
        ];
    }

    public function example(): array
    {
        return [
            'company' => 'Main company', 'branch' => 'Main branch', 'name' => 'Amina Otieno', 'organisation' => 'Duka Moja Ltd', 'job_title' => 'Buyer',
            'email' => 'amina@example.com', 'phone' => '+254722000111', 'source' => 'Website', 'owner_email' => '', 'estimated_value' => '150000', 'currency' => 'KES', 'notes' => '',
        ];
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $byName = fn (string $key, callable $query, string $column = 'name') => $context->remember($key, fn () => $query()->get(['id', $column])
            ->groupBy(fn ($row) => Str::lower((string) $row->{$column}))->map(fn ($rows) => $rows->pluck('id')->all())->all());
        $one = function (array $options, string $column, string $value) use (&$errors): ?string {
            $ids = array_values(array_unique($options[Str::lower($value)] ?? []));

            if (count($ids) === 1) {
                return $ids[0];
            }

            $errors[$column][] = __($ids === [] ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $value]);

            return null;
        };

        $companyId = $one($byName('companies', fn () => Company::query()->whereNull('archived_at')), 'company', (string) ($values['company'] ?? ''));

        if ($companyId === null) {
            throw new RowRejected($errors);
        }

        $input = [
            'branch_id' => $one($byName("branches|{$companyId}", fn () => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')), 'branch', (string) ($values['branch'] ?? '')),
            'channel' => 'api',
        ];

        foreach (['name' => 'name', 'organisation' => 'organisation', 'job_title' => 'title', 'email' => 'email', 'phone' => 'phone', 'notes' => 'notes', 'currency' => 'currency'] as $column => $field) {
            if (array_key_exists($column, $values)) {
                $input[$field] = $column === 'currency' ? strtoupper((string) $values[$column]) : $values[$column];
            }
        }

        if (array_key_exists('source', $values)) {
            $input['source_id'] = $one($byName("sources|{$companyId}", fn () => Option::query()->where('company_id', $companyId)->where('kind', Option::SOURCE)->whereNull('archived_at')), 'source', (string) $values['source']);
        }

        if (array_key_exists('owner_email', $values) && $values['owner_email'] !== '') {
            $input['owner_id'] = $one($byName('users', fn () => User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('email'), 'email'), 'owner_email', (string) $values['owner_email']);
        }

        if (array_key_exists('estimated_value', $values)) {
            $currency = $input['currency'] ?? (string) Company::query()->whereKey($companyId)->value('base_currency');

            try {
                $input['estimated_value_minor'] = Money::parse((string) $values['estimated_value'], $currency, app(CurrencyDecimals::class))->minor();
                $input['currency'] = $currency;
            } catch (Throwable) {
                $errors['estimated_value'][] = __('imports.errors.unknown', ['value' => (string) $values['estimated_value']]);
            }
        }

        $custom = $this->customInput($values);

        if ($custom !== []) {
            $input['custom'] = $custom;
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        return new PreparedRow($number, $input);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = app(DomainRequests::class)->validate(LeadRequest::class, $row->input, $context->user);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        return ['record' => app(Leads::class)->create($row->validated, $context->user, 'import'), 'before' => null];
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $lead = Lead::query()->whereKey($row->record_id)->first();

        if ($lead !== null && ! $lead->isArchived() && $lead->status !== Lead::CONVERTED) {
            app(Leads::class)->archive($lead, true);
        }
    }

    public function companiesOf(array $ids): array
    {
        return Lead::query()->whereIn('id', $ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['crm.lead.view'];
    }

    public function describe(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
