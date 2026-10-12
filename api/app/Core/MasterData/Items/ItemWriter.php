<?php

namespace App\Core\MasterData\Items;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\MasterData\Items\Http\Requests\ItemRules;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-02: creates and changes items from validated input (ItemRules), for
 * the API (ItemController) and imports (IMP-01) alike. Codes and barcodes
 * are unique among active items in the sharing scope, checked under the
 * items sharing lock (review focus 4). Every change is audited on the item
 * (MD-07), unit and barcode lists as `core.item.units_update` and
 * `core.item.barcodes_update`.
 */
class ItemWriter
{
    public function __construct(
        private readonly MasterDataSharing $sharing,
        private readonly ItemUniqueness $uniqueness,
        private readonly Auditor $auditor,
        private readonly ItemReferences $references,
        private readonly CustomFieldWriter $customFields,
    ) {}

    public function create(array $data): Item
    {
        $companyId = $data['company_id'] ?? null;

        return $this->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $companyId) {
            // Lock order, as in MasterDataSharing::switch: the sharing lock first, then rows.
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $this->assertModeUnchanged($companyId);

            $barcodes = ItemRules::barcodes($data, $data['base_uom_id']) ?? [];
            $this->uniqueness->assert($companyId, $data['code'], array_column($barcodes, 'barcode'), null);
            $this->references->assertActive(
                $companyId, $data['category_id'] ?? null, $data['tax_category_id'] ?? null, $data['base_uom_id'], array_column($data['uoms'] ?? [], 'uom_id'),
            );

            $item = new Item(['company_id' => $companyId, ...ItemRules::attributes($data)]);
            // CF-01, CF-02: defaults, formulas and unique fields, then the item's files.
            $this->customFields->fill(ItemEntity::KEY, $item, $data['custom'] ?? null, creating: true);
            $item->save();
            $this->customFields->saved(ItemEntity::KEY, $item);
            $this->syncUoms($item, $data['uoms'] ?? []);
            $this->syncBarcodes($item, $barcodes);

            return $item;
        }));
    }

    public function update(Item $item, array $data): Item
    {
        return $this->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item, $data) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $item->company_id;
            $this->assertModeUnchanged($companyId);

            $baseUomId = $data['base_uom_id'] ?? $item->base_uom_id;
            $barcodes = ItemRules::barcodes($data, $baseUomId);
            $this->uniqueness->assert(
                $companyId,
                $data['code'] ?? (string) $item->code,
                $barcodes === null ? $this->storedBarcodes($item) : array_column($barcodes, 'barcode'),
                $item->id,
            );
            $this->references->assertActive(
                $companyId,
                array_key_exists('category_id', $data) ? $data['category_id'] : $item->category_id,
                array_key_exists('tax_category_id', $data) ? $data['tax_category_id'] : $item->tax_category_id,
                $baseUomId,
                array_key_exists('uoms', $data) ? array_column($data['uoms'], 'uom_id') : ItemUom::query()->where('item_id', $item->id)->pluck('uom_id')->all(),
            );

            $item->fill(['company_id' => $companyId, ...ItemRules::attributes($data)]);
            $this->customFields->fill(ItemEntity::KEY, $item, $data['custom'] ?? null, creating: false);
            $item->save();
            $this->customFields->saved(ItemEntity::KEY, $item);

            if (array_key_exists('uoms', $data)) {
                $this->syncUoms($item, $data['uoms']);
            }

            if ($barcodes !== null) {
                $this->syncBarcodes($item, $barcodes);
            }

            return $item;
        }));
    }

    /**
     * The item's other units as stored, for audit entries and before images.
     *
     * @return list<array<string, mixed>>
     */
    public function unitsOf(Item $item): array
    {
        return ItemUom::query()->where('item_id', $item->id)->orderBy('uom_id')->get()
            ->map(fn (ItemUom $uom) => [
                'uom_id' => $uom->uom_id,
                'factor' => (string) BigDecimal::of($uom->factor)->strippedOfTrailingZeros(),
                'is_sales_default' => $uom->is_sales_default,
                'is_purchase_default' => $uom->is_purchase_default,
            ])->all();
    }

    /** @return list<array{barcode: string, uom_id: ?string}> */
    public function barcodesOf(Item $item): array
    {
        return ItemBarcode::query()->where('item_id', $item->id)->orderBy('barcode')->get()
            ->map(fn (ItemBarcode $barcode) => ['barcode' => $barcode->barcode, 'uom_id' => $barcode->uom_id])->all();
    }

    /**
     * Replace the item's other units; one audit entry for the change.
     *
     * @param  list<array{uom_id: string, factor: int|string, is_sales_default?: bool, is_purchase_default?: bool}>  $uoms
     */
    private function syncUoms(Item $item, array $uoms): void
    {
        $before = $this->unitsOf($item);
        ItemUom::query()->where('item_id', $item->id)->get()->each(fn (ItemUom $uom) => $uom->delete());

        foreach ($uoms as $uom) {
            ItemUom::create([
                'item_id' => $item->id,
                'uom_id' => $uom['uom_id'],
                'factor' => (string) BigDecimal::of((string) $uom['factor']),
                'is_sales_default' => (bool) ($uom['is_sales_default'] ?? false),
                'is_purchase_default' => (bool) ($uom['is_purchase_default'] ?? false),
            ]);
        }

        $after = $this->unitsOf($item);

        if ($after !== $before) {
            $this->auditor->record('core.item.units_update', $item, ['uoms' => $before], ['uoms' => $after]);
        }
    }

    /** @param list<array{barcode: string, uom_id: ?string}> $barcodes */
    private function syncBarcodes(Item $item, array $barcodes): void
    {
        $before = $this->barcodesOf($item);
        ItemBarcode::query()->where('item_id', $item->id)->get()->each(fn (ItemBarcode $barcode) => $barcode->delete());

        foreach ($barcodes as $barcode) {
            ItemBarcode::create(['item_id' => $item->id, ...$barcode]);
        }

        $after = $this->barcodesOf($item);

        if ($after !== $before) {
            $this->auditor->record('core.item.barcodes_update', $item, ['barcodes' => $before], ['barcodes' => $after]);
        }
    }

    /** @return list<string> */
    public function storedBarcodes(Item $item): array
    {
        return ItemBarcode::query()->where('item_id', $item->id)->pluck('barcode')->all();
    }

    /** Re-checked under the sharing lock: the mode may have changed since validation (TEN-08). */
    public function assertModeUnchanged(?string $companyId): void
    {
        if (! ItemSharing::fits($companyId)) {
            throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
        }
    }

    /**
     * A unique index caught a race the checks under the lock could not see: same errors as the checks.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public function writing(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->uniqueness->fromViolation($e) ?? $e;
        }
    }
}
