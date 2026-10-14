<?php

namespace Modules\Stores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Qty;

/**
 * STR-11: the own ledger's quantity of an item at a store: the balance
 * after its latest movement, in the item's base unit.
 *
 * @mixin Movement
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Movement $movement */
        $movement = $this->resource;

        return [
            'id' => $movement->location_id.':'.$movement->item_id,
            'company' => Refs::named($movement->company),
            'location' => Refs::named($movement->location),
            'item' => Refs::item($movement->item),
            'unit' => Refs::uom($movement->item?->baseUom),
            'qty' => Qty::show($movement->balance_after ?? '0'),
            'last_moved_at' => Refs::at($movement->occurred_at),
        ];
    }
}
