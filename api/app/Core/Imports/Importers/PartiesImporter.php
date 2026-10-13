<?php

namespace App\Core\Imports\Importers;

use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Parties\Http\Requests\StorePartyRequest;
use App\Core\MasterData\Parties\Http\Requests\UpdatePartyRequest;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Parties\PartyWriter;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IMP-01: parties of one role (MD-01): customers or suppliers, with their
 * phones, emails, an address, terms, credit limit, price list (by name),
 * tags and custom fields (CF-03). Matched by tax ID in update mode; an
 * existing party matched gains the role. Each row passes StorePartyRequest
 * or UpdatePartyRequest (credit limit rules included, WF-01) and is saved
 * by PartyWriter.
 */
abstract class PartiesImporter extends Importer
{
    private const ATTRIBUTES = [
        'company_id', 'kind', 'name', 'legal_name', 'tax_id', 'phones', 'emails', 'addresses', 'currency',
        'payment_terms_days', 'credit_limit_minor', 'credit_limit_currency', 'price_list_id', 'tags', 'roles',
    ];

    private const ADDRESS = ['address_line1' => 'line1', 'address_line2' => 'line2', 'city' => 'city', 'region' => 'region', 'postal_code' => 'postal_code', 'country' => 'country'];

    /** The role every imported party has (PartyRoles). */
    abstract protected function role(): string;

    public function model(): string
    {
        return Party::class;
    }

    public function customFieldEntity(): string
    {
        return PartyEntity::KEY;
    }

    public function usageEntity(): string
    {
        return 'party';
    }

    public function permissions(string $mode): array
    {
        return $mode === 'upsert' ? ['core.party.create', 'core.party.edit'] : ['core.party.create'];
    }

    public function matchColumns(): array
    {
        return ['tax_id'];
    }

    protected function baseColumns(): array
    {
        $columns = [
            ImportColumn::text('name', true),
            ImportColumn::enum('kind', Party::KINDS, 'core.party.kinds'),
            ImportColumn::text('legal_name'),
            ImportColumn::text('tax_id'),
            ImportColumn::of('phones', 'list'),
            ImportColumn::of('emails', 'list'),
            ImportColumn::text('address_line1'),
            ImportColumn::text('address_line2'),
            ImportColumn::text('city'),
            ImportColumn::text('region'),
            ImportColumn::text('postal_code'),
            ImportColumn::text('country'),
            ImportColumn::text('currency'),
            ImportColumn::of('payment_terms_days', 'integer'),
            ImportColumn::of('credit_limit', 'decimal'),
            ImportColumn::text('credit_limit_currency'),
            ImportColumn::text('price_list'),
            ImportColumn::of('tags', 'list'),
        ];

        // TEN-08: parties kept per company name their company.
        if (PartyRoles::perCompany([$this->role()], app(MasterDataSharing::class))) {
            array_splice($columns, 1, 0, [ImportColumn::text('company', true)]);
        }

        return $columns;
    }

    protected function fieldColumns(): array
    {
        return [
            'company_id' => 'company', 'kind' => 'kind', 'name' => 'name', 'legal_name' => 'legal_name', 'tax_id' => 'tax_id',
            'phones' => 'phones', 'emails' => 'emails', 'addresses' => 'address_line1', 'currency' => 'currency',
            'payment_terms_days' => 'payment_terms_days', 'credit_limit' => 'credit_limit', 'credit_limit_currency' => 'credit_limit_currency',
            'price_list_id' => 'price_list', 'tags' => 'tags',
        ];
    }

    public function identity(array $values): ?string
    {
        return isset($values['tax_id']) ? self::taxId($values['tax_id']) : null;
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $input = [];
        $companyId = null;

        if (array_key_exists('company', $values)) {
            $companyId = $context->remember('companies', fn () => Company::query()->whereNull('archived_at')->get(['id', 'name'])
                ->mapWithKeys(fn (Company $company) => [Str::lower($company->name) => $company->id])->all())[Str::lower($values['company'])] ?? null;
            $companyId === null ? $errors['company'][] = __('imports.errors.unknown', ['value' => $values['company']]) : $input['company_id'] = $companyId;
        }

        foreach (['kind', 'name', 'legal_name', 'tax_id', 'currency', 'payment_terms_days', 'credit_limit', 'credit_limit_currency', 'tags'] as $key) {
            if (array_key_exists($key, $values)) {
                $input[$key] = $values[$key];
            }
        }

        foreach (['currency', 'credit_limit_currency'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = Str::upper($input[$key]);
            }
        }

        if (array_key_exists('payment_terms_days', $input)) {
            $input['payment_terms_days'] = (int) $input['payment_terms_days'];
        }

        if (array_key_exists('phones', $values)) {
            $input['phones'] = array_map(fn (string $number) => ['number' => $number], $values['phones']);
        }

        if (array_key_exists('emails', $values)) {
            $input['emails'] = array_map(fn (string $address) => ['address' => $address], $values['emails']);
        }

        $address = [];

        foreach (self::ADDRESS as $column => $field) {
            if (array_key_exists($column, $values)) {
                $address[$field] = $field === 'country' ? Str::upper($values[$column]) : $values[$column];
            }
        }

        if ($address !== []) {
            $input['addresses'] = [$address];
        }

        if (array_key_exists('price_list', $values)) {
            $ids = $context->remember("price_lists|{$companyId}", fn () => PriceList::query()->whereNull('archived_at')
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->get(['id', 'name'])->groupBy(fn (PriceList $list) => Str::lower($list->name))
                ->map(fn ($lists) => $lists->pluck('id')->all())->all())[Str::lower($values['price_list'])] ?? [];

            if (count($ids) === 1) {
                $input['price_list_id'] = $ids[0];
            } else {
                $errors['price_list'][] = __($ids === [] ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values['price_list']]);
            }
        }

        $custom = $this->customInput($values);

        if ($custom !== []) {
            $input['custom'] = $custom;
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        $existing = isset($values['tax_id']) ? Party::query()->whereNull('archived_at')->where('tax_id', self::taxId($values['tax_id']))->first() : null;

        if ($existing !== null && ! $context->updates()) {
            throw new RowRejected(['tax_id' => [__('imports.errors.exists', ['value' => $values['tax_id']])]]);
        }

        // A new party is an organisation unless the file says otherwise.
        if ($existing === null && ! array_key_exists('kind', $input)) {
            $input['kind'] = 'organisation';
        }

        // An existing party keeps its roles and gains this one; a new one has this role.
        $roles = $existing === null ? [$this->role()] : array_values(array_unique([...$existing->roles, $this->role()]));

        if ($existing === null || $roles !== $existing->roles) {
            $input['roles'] = $roles;
        }

        return new PreparedRow($number, $input, $existing);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = $row->creates()
            ? app(DomainRequests::class)->validate(StorePartyRequest::class, $row->input, $context->user)
            : app(DomainRequests::class)->validate(UpdatePartyRequest::class, $row->input, $context->user, ['party' => $row->record]);
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $writer = app(PartyWriter::class);

        if ($row->creates()) {
            return ['record' => $writer->create($row->validated), 'before' => null];
        }

        $party = Party::query()->whereKey($row->record->getKey())->firstOrFail();
        $before = [...$party->only(self::ATTRIBUTES), 'custom' => (array) ($party->custom ?? [])];

        return ['record' => $writer->update($party, $row->validated, $context->user), 'before' => $before];
    }

    /**
     * Created: archived. Updated: the stored values before the import come
     * back as they were (already normalised), in one audited save.
     */
    public function undo(ImportRow $row, ImportContext $context): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($row) {
            $party = Party::query()->whereKey($row->record_id)->lockForUpdate()->first();

            if ($party === null) {
                return;
            }

            if ($row->action === 'created') {
                if (! $party->isArchived()) {
                    $party->archive();
                }

                return;
            }

            $before = $row->before ?? [];
            $party->fill(array_intersect_key($before, array_flip(self::ATTRIBUTES)));
            $custom = (array) ($before['custom'] ?? []);
            $party->custom = $custom === [] ? new \stdClass : $custom;
            $party->save();
        });
    }

    public function describe(Model $record): string
    {
        return trim($record->name.($record->tax_id ? " ({$record->tax_id})" : ''));
    }

    private static function taxId(string $value): string
    {
        return Str::upper((string) preg_replace('/\s+/u', '', $value));
    }
}
