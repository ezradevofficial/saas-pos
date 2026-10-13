<?php

namespace Modules\Inventory\Http\Resources;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Stock\Qty;

/**
 * INV-11: a reorder rule, the stock at its place now (the list query adds
 * `on_hand`) and whether it is low.
 *
 * @mixin ReorderRule
 */
class ReorderRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ReorderRule $rule */
        $rule = $this->resource;
        $onHand = BigDecimal::of((string) ($rule->getAttribute('on_hand') ?? '0'));

        return [
            'id' => $rule->id,
            'item' => Refs::item($rule->item),
            'base_uom' => Refs::uom($rule->item?->baseUom),
            'company_id' => $rule->company_id,
            'location' => Refs::place($rule->location),
            'reorder_level' => Qty::show($rule->reorder_level),
            'reorder_qty' => Qty::show($rule->reorder_qty),
            'on_hand' => Qty::show($onHand),
            'low' => $rule->archived_at === null && $onHand->isLessThanOrEqualTo((string) $rule->reorder_level),
            'low_since' => Refs::at($rule->low_since),
            'archived_at' => Refs::at($rule->archived_at),
            'updated_at' => Refs::at($rule->updated_at),
        ];
    }
}
