<?php

namespace Modules\Stores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Qty;

/**
 * STR-10: one row of the store ledger: kind, document, signed quantity in
 * the base unit, the balance after it (own ledger), its value once valued
 * (holders of `stores.value.view` at the store).
 *
 * @mixin Movement
 */
class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Movement $movement */
        $movement = $this->resource;
        $values = Values::visible($request, $movement->location_id);

        return [
            'id' => $movement->id,
            'kind' => $movement->kind,
            'ledger' => $movement->ledger,
            'occurred_at' => Refs::at($movement->occurred_at),
            'company' => Refs::named($movement->company),
            'location' => Refs::named($movement->location),
            'item' => Refs::item($movement->item),
            'unit' => Refs::uom($movement->item?->baseUom),
            'qty' => Qty::show($movement->qty),
            'uom' => Refs::uom($movement->uom),
            'uom_qty' => Qty::show($movement->uom_qty),
            'balance_after' => Qty::show($movement->balance_after),
            'value' => $values ? Values::money($movement->value_minor, $movement->currency) : null,
            'source' => ['type' => $movement->source_type, 'id' => $movement->source_id, 'reference' => $movement->reference],
            'department' => Refs::dimension($movement->department),
            'cost_centre' => Refs::dimension($movement->costCentre),
            'reason' => $movement->reason,
            'note' => $movement->note,
            'created_by' => Refs::user($movement->creator),
        ];
    }
}
