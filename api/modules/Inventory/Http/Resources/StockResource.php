<?php

namespace Modules\Inventory\Http\Resources;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Stock\Qty;

/**
 * INV-05, INV-11, INV-19: stock on hand of an item at a location, its
 * reorder level and whether it is low, and its value at the company's
 * unit cost (valuation holders only, RBAC-05). The list query adds the
 * rule and cost columns (StockController).
 *
 * @mixin Balance
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Balance $balance */
        $balance = $this->resource;
        $qty = BigDecimal::of((string) $balance->qty);
        $level = $balance->getAttribute('reorder_level');

        return [
            'id' => $balance->id,
            'item' => Refs::item($balance->item),
            'base_uom' => Refs::uom($balance->item?->baseUom),
            'company_id' => $balance->company_id,
            'location' => Refs::place($balance->location),
            'qty' => Qty::show($qty),
            'reorder_level' => Qty::show($level),
            'reorder_qty' => Qty::show($balance->getAttribute('reorder_qty')),
            'low' => $level !== null && $qty->isLessThanOrEqualTo((string) $level),
            'value' => Values::visible($request, $balance->location_id) ? self::value($balance, $qty) : null,
            'updated_at' => Refs::at($balance->updated_at),
        ];
    }

    /** qty × the company's unit cost (its average while it holds some, else its last receipt's). */
    public static function value(Balance $balance, BigDecimal $qty): ?array
    {
        $currency = $balance->getAttribute('cost_currency');

        if ($currency === null) {
            return null;
        }

        $held = BigDecimal::of((string) ($balance->getAttribute('cost_qty') ?? '0'));
        $unit = match (true) {
            $held->isPositive() => BigDecimal::of((string) $balance->getAttribute('cost_value'))->dividedBy($held, 20, RoundingMode::HalfUp),
            $balance->getAttribute('last_cost_minor') !== null && BigDecimal::of((string) $balance->getAttribute('last_cost_qty'))->isPositive() => BigDecimal::of((string) $balance->getAttribute('last_cost_minor'))->dividedBy((string) $balance->getAttribute('last_cost_qty'), 20, RoundingMode::HalfUp),
            default => BigDecimal::zero(),
        };

        return Values::money((string) Qty::round($unit->multipliedBy($qty)), $currency);
    }
}
