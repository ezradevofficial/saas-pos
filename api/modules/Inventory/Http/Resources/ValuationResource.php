<?php

namespace Modules\Inventory\Http\Resources;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Stock\Qty;

/**
 * INV-20: what a company holds of an item (in transit included), its value
 * in the base currency and its unit cost (minor units per base unit, up to
 * 4 decimals). The list is for holders of `inventory.valuation.view` at
 * the company (or the tenant).
 *
 * @mixin CostState
 */
class ValuationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CostState $state */
        $state = $this->resource;
        $qty = BigDecimal::of((string) $state->qty);

        return [
            'id' => $state->id,
            'company' => Refs::place($state->company),
            'item' => Refs::item($state->item),
            'base_uom' => Refs::uom($state->item?->baseUom),
            'qty' => Qty::show($qty),
            'value' => Values::money($state->value_minor, $state->currency),
            'unit_cost_minor' => $qty->isPositive()
                ? (string) BigDecimal::of((string) $state->value_minor)->dividedBy($qty, 4, RoundingMode::HalfUp)->strippedOfTrailingZeros()
                : null,
            'currency' => $state->currency,
            'updated_at' => $state->updated_at?->toIso8601String(),
        ];
    }
}
