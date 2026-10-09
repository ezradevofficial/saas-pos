<?php

namespace App\Core\Sync\Sources;

use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MD-02, NFR-04: the items a till sells: the group's shared items and the
 * device's company's, active only, with their other units, barcodes and
 * images inside (a change to any of them re-stamps the item). `sellable`
 * and `reason` say whether the item's tax is known for the company today
 * (ItemTaxStatus, "Rate needed" items are refused); the tax data changing
 * re-stamps the items concerned, and the `tax_codes` entity lets the
 * device check again when a dated rate starts while it is offline.
 *
 * Images are fetched with the device token from `GET sync/media/{id}`.
 * `custom` holds the values of the custom fields shown on the POS
 * (CF-03; their labels in `custom_fields`, CustomFieldSource); other custom
 * fields and module fields (costing) are not sent. Version 2 added `custom`.
 */
class ItemSource implements IncrementalSource
{
    public function __construct(private readonly ItemTaxStatus $taxStatus) {}

    public function key(): string
    {
        return 'items';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 2;
    }

    public function table(): string
    {
        return 'items';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('items.company_id')->orWhere('items.company_id', $scope->companyId()));
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        $db = DB::connection(TenantContext::CONNECTION);

        $items = $this->visible($db->table('items'), $scope)
            ->whereIn('items.id', $ids)
            ->whereNull('items.archived_at')
            ->get(['id', 'company_id', 'code', 'name', 'category_id', 'type', 'base_uom_id', 'tax_category_id', 'custom', 'updated_at']);

        if ($items->isEmpty()) {
            return [];
        }

        $itemIds = $items->pluck('id')->all();
        $uoms = $db->table('item_uoms')->whereIn('item_id', $itemIds)->orderBy('factor')->orderBy('id')
            ->get(['item_id', 'uom_id', 'factor', 'is_sales_default'])->groupBy('item_id');
        $barcodes = $db->table('item_barcodes')->whereIn('item_id', $itemIds)->orderBy('barcode')
            ->get(['item_id', 'uom_id', 'barcode'])->groupBy('item_id');
        $images = $db->table('item_images')->whereIn('item_id', $itemIds)->orderBy('position')
            ->get(['id', 'item_id', 'position', 'mime', 'width', 'height'])->groupBy('item_id');
        $taxes = $this->taxStatus->forCompany($scope->company, $scope->at);
        $customFields = CustomFieldSource::posFields(ItemEntity::KEY);

        $rows = [];

        foreach ($items as $item) {
            $tax = ItemTaxStatus::of($item->tax_category_id, $taxes);

            $rows[$item->id] = [
                'id' => $item->id,
                'code' => (string) $item->code,
                'name' => $item->name,
                'type' => $item->type,
                'category_id' => $item->category_id,
                'base_uom_id' => $item->base_uom_id,
                'tax_category_id' => $item->tax_category_id,
                'tax_code_id' => $tax['tax_code_id'],
                'sellable' => $tax['sellable'],
                'reason' => $tax['reason'],
                'shared' => $item->company_id === null,
                'uoms' => $uoms->get($item->id, collect())->map(fn (object $u) => [
                    'uom_id' => $u->uom_id,
                    'factor' => (string) BigDecimal::of($u->factor)->strippedOfTrailingZeros(),
                    'is_sales_default' => (bool) $u->is_sales_default,
                ])->values()->all(),
                'barcodes' => $barcodes->get($item->id, collect())->map(fn (object $b) => [
                    'barcode' => $b->barcode,
                    'uom_id' => $b->uom_id,
                ])->values()->all(),
                'images' => $images->get($item->id, collect())->map(fn (object $i) => [
                    'id' => $i->id,
                    'position' => (int) $i->position,
                    'mime' => $i->mime,
                    'width' => (int) $i->width,
                    'height' => (int) $i->height,
                    'url' => '/api/v1/sync/media/'.$i->id,
                ])->values()->all(),
                'custom' => (object) CustomFieldSource::values($customFields, $item->custom),
                'updated_at' => Iso::of($item->updated_at),
            ];
        }

        return $rows;
    }
}
