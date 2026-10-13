<?php

namespace App\Core\Imports\Importers;

use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportCells;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\Items\Barcode;
use App\Core\MasterData\Items\Http\Requests\StoreItemRequest;
use App\Core\MasterData\Items\Http\Requests\UpdateItemRequest;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Items\ItemUniqueness;
use App\Core\MasterData\Items\ItemWriter;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * IMP-01: items (MD-02) with their category and tax category (by name),
 * base unit and other units (by code: `BOX=24; PACK=6`), barcodes and
 * custom fields (CF-03). Matched by code in update mode. Each row passes
 * StoreItemRequest or UpdateItemRequest and is saved by ItemWriter, like
 * an item typed in the app.
 */
class ItemsImporter extends Importer
{
    public function key(): string
    {
        return 'items';
    }

    public function model(): string
    {
        return Item::class;
    }

    public function customFieldEntity(): string
    {
        return ItemEntity::KEY;
    }

    public function usageEntity(): string
    {
        return 'item';
    }

    public function permissions(string $mode): array
    {
        return $mode === 'upsert' ? ['core.item.create', 'core.item.edit'] : ['core.item.create'];
    }

    public function matchColumns(): array
    {
        return ['code'];
    }

    protected function baseColumns(): array
    {
        $columns = [
            ImportColumn::text('code', true),
            ImportColumn::text('name', true),
            ImportColumn::enum('type', Item::TYPES, 'core.item.types', true),
            ImportColumn::text('base_unit', true),
            ImportColumn::text('category'),
            ImportColumn::text('tax_category'),
            ImportColumn::of('barcodes', 'list'),
            ImportColumn::text('units'),
        ];

        // TEN-08: items kept per company name their company.
        if (! app(MasterDataSharing::class)->isShared(ItemSharing::DATA_TYPE)) {
            array_splice($columns, 2, 0, [ImportColumn::text('company', true)]);
        }

        return $columns;
    }

    public function example(): array
    {
        return [
            'code' => 'SODA-500', 'name' => 'Soda 500 ml', 'company' => 'Main company', 'type' => 'stock', 'base_unit' => 'EA',
            'category' => 'Drinks', 'tax_category' => 'Standard', 'barcodes' => '6161234567890', 'units' => 'BOX=24',
        ];
    }

    protected function fieldColumns(): array
    {
        return [
            'company_id' => 'company', 'code' => 'code', 'name' => 'name', 'type' => 'type', 'base_uom_id' => 'base_unit',
            'category_id' => 'category', 'tax_category_id' => 'tax_category', 'barcodes' => 'barcodes', 'uoms' => 'units',
        ];
    }

    public function identity(array $values): ?string
    {
        return isset($values['code']) ? Str::lower(($values['company'] ?? '').'|'.$values['code']) : null;
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $errors = [];
        $input = [];
        $companyId = null;

        if (array_key_exists('company', $values)) {
            $companyId = $this->companies($context)[Str::lower($values['company'])] ?? null;
            $companyId === null ? $errors['company'][] = __('imports.errors.unknown', ['value' => $values['company']]) : $input['company_id'] = $companyId;
        }

        foreach (['code', 'name', 'type'] as $key) {
            if (array_key_exists($key, $values)) {
                $input[$key] = $values[$key];
            }
        }

        $units = $this->units($context);

        if (array_key_exists('base_unit', $values)) {
            $uomId = $units[Str::lower($values['base_unit'])] ?? null;
            $uomId === null ? $errors['base_unit'][] = __('imports.errors.unknown_unit', ['value' => $values['base_unit']]) : $input['base_uom_id'] = $uomId;
        }

        foreach (['category' => ['category_id', ItemCategory::class], 'tax_category' => ['tax_category_id', TaxCategory::class]] as $column => [$field, $model]) {
            if (array_key_exists($column, $values)) {
                $ids = $this->named($context, $model, $companyId)[Str::lower($values[$column])] ?? [];

                if (count($ids) === 1) {
                    $input[$field] = $ids[0];
                } else {
                    $errors[$column][] = __($ids === [] ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values[$column]]);
                }
            }
        }

        if (array_key_exists('units', $values)) {
            $input['uoms'] = [];

            foreach (app(ImportCells::class)->list($values['units'], ";\n") as $pair) {
                [$code, $factor] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
                $uomId = $units[Str::lower($code)] ?? null;

                if ($uomId === null || $factor === '') {
                    $errors['units'][] = __('imports.errors.unit_pair', ['value' => $pair]);

                    continue;
                }

                $input['uoms'][] = ['uom_id' => $uomId, 'factor' => str_replace(',', '.', $factor)];
            }
        }

        if (array_key_exists('barcodes', $values)) {
            $input['barcodes'] = array_map(fn (string $barcode) => ['barcode' => $barcode], $values['barcodes']);
        }

        $custom = $this->customInput($values);

        if ($custom !== []) {
            $input['custom'] = $custom;
        }

        if ($errors !== []) {
            throw new RowRejected($errors);
        }

        $existing = isset($values['code']) ? $this->existing($values['code'], $companyId) : null;

        if ($existing !== null && ! $context->updates()) {
            throw new RowRejected(['code' => [__('imports.errors.exists', ['value' => $values['code']])]]);
        }

        return new PreparedRow($number, $input, $existing);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = $row->creates()
            ? app(DomainRequests::class)->validate(StoreItemRequest::class, $row->input, $context->user)
            : app(DomainRequests::class)->validate(UpdateItemRequest::class, $row->input, $context->user, ['item' => $row->record]);
    }

    public function check(PreparedRow $row, ImportContext $context): void
    {
        $data = $row->validated;
        /** @var Item|null $item */
        $item = $row->record;
        $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $item?->company_id;
        $barcodes = array_map(fn (array $entry) => Barcode::normalise($entry['barcode']), $data['barcodes'] ?? []);

        try {
            app(ItemUniqueness::class)->assert($companyId, $data['code'] ?? (string) $item?->code, $barcodes, $item?->id);
        } catch (ValidationException $e) {
            throw RowRejected::from($e);
        }
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $writer = app(ItemWriter::class);

        if ($row->creates()) {
            return ['record' => $writer->create($row->validated), 'before' => null];
        }

        /** @var Item $item */
        $item = Item::query()->whereKey($row->record->getKey())->firstOrFail();
        $before = [
            ...$item->only(['company_id', 'code', 'name', 'type', 'base_uom_id', 'category_id', 'tax_category_id']),
            'custom' => (array) ($item->custom ?? []),
            'uoms' => $writer->unitsOf($item),
            'barcodes' => $writer->barcodesOf($item),
        ];

        return ['record' => $writer->update($item, $row->validated), 'before' => $before];
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $item = Item::query()->whereKey($row->record_id)->first();

        if ($item === null) {
            return;
        }

        if ($row->action === 'created') {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item) {
                $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

                if (! $item->isArchived()) {
                    $item->archive();
                }
            });

            return;
        }

        $before = $row->before ?? [];
        $before['custom'] = self::customRestore((array) ($before['custom'] ?? []), (array) ($item->custom ?? []));
        app(ItemWriter::class)->update($item, $before);
    }

    public function describe(Model $record): string
    {
        return trim($record->code.' '.$record->name);
    }

    /** @return array<string, string> lower-case code => active unit id */
    private function units(ImportContext $context): array
    {
        return $context->remember('units', fn () => Uom::query()->whereNull('archived_at')->get(['id', 'code'])
            ->mapWithKeys(fn (Uom $uom) => [Str::lower($uom->code) => $uom->id])->all());
    }

    /** @return array<string, string> lower-case name => company id */
    private function companies(ImportContext $context): array
    {
        return $context->remember('companies', fn () => Company::query()->whereNull('archived_at')->get(['id', 'name'])
            ->mapWithKeys(fn (Company $company) => [Str::lower($company->name) => $company->id])->all());
    }

    /**
     * Active records of $model in the item's scope (the company, or shared), by lower-case name.
     *
     * @param  class-string<Model>  $model
     * @return array<string, list<string>>
     */
    private function named(ImportContext $context, string $model, ?string $companyId): array
    {
        return $context->remember("{$model}|{$companyId}", fn () => $model::query()->whereNull('archived_at')
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->get(['id', 'name'])->groupBy(fn (Model $record) => Str::lower($record->name))
            ->map(fn ($records) => $records->pluck('id')->all())->all());
    }

    private function existing(string $code, ?string $companyId): ?Item
    {
        return Item::query()->whereNull('archived_at')->whereCode($code)
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->first();
    }
}
