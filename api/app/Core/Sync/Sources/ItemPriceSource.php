<?php

namespace App\Core\Sync\Sources;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MD-03 follow-up, NFR-04, CUR-05: the item prices a till sells with: the
 * prices of its company's price lists (the `price_lists` snapshot), per
 * item, unit, start date and quantity break, scheduled ones included so
 * a change starts on its day while the till is offline. The device picks
 * the price the way PriceResolver does (an explicit unit price, else the
 * base unit's × factor, rounded half up once).
 *
 * Sent only while usable: the price and its list active, the item active
 * and shared or the company's, the unit still the item's. Anything else
 * reaches the device as a tombstone (migration 2026_10_19_000300
 * re-stamps prices when their list, item or units change). Amounts travel
 * as strings of minor units (ADR 003).
 */
class ItemPriceSource implements IncrementalSource
{
    public function key(): string
    {
        return 'item_prices';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'item_prices';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->whereIn('item_prices.price_list_id', fn (Builder $q) => $q->select('id')->from('price_lists')->where('company_id', $scope->companyId()));
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        $rows = [];
        $prices = $this->visible(DB::connection(TenantContext::CONNECTION)->table('item_prices'), $scope)
            ->join('price_lists', 'price_lists.id', '=', 'item_prices.price_list_id')
            ->join('items', 'items.id', '=', 'item_prices.item_id')
            ->whereIn('item_prices.id', $ids)
            ->whereNull('item_prices.archived_at')
            ->whereNull('price_lists.archived_at')
            ->whereNull('items.archived_at')
            ->where(fn (Builder $q) => $q->whereNull('items.company_id')->orWhere('items.company_id', $scope->companyId()))
            ->where(fn (Builder $q) => $q->whereColumn('item_prices.uom_id', 'items.base_uom_id')
                ->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('item_uoms')
                    ->whereColumn('item_uoms.item_id', 'item_prices.item_id')
                    ->whereColumn('item_uoms.uom_id', 'item_prices.uom_id')))
            ->get([
                'item_prices.id', 'item_prices.price_list_id', 'item_prices.item_id', 'item_prices.uom_id', 'item_prices.amount_minor',
                'item_prices.currency', 'item_prices.effective_from', 'item_prices.min_quantity', 'item_prices.updated_at',
            ]);

        foreach ($prices as $price) {
            $rows[$price->id] = [
                'id' => $price->id,
                'price_list_id' => $price->price_list_id,
                'item_id' => $price->item_id,
                'uom_id' => $price->uom_id,
                'amount_minor' => (string) $price->amount_minor,
                'currency' => $price->currency,
                'effective_from' => substr((string) $price->effective_from, 0, 10),
                'min_quantity' => (string) BigDecimal::of((string) $price->min_quantity)->strippedOfTrailingZeros(),
                'updated_at' => Iso::of($price->updated_at),
            ];
        }

        return $rows;
    }
}
