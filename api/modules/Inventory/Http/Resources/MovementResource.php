<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;

/**
 * INV-04, INV-19: one ledger row: what moved where, in the base unit and
 * as entered, the balance after it, its source document and flags, and
 * its value (valuation holders only, RBAC-05).
 *
 * @mixin Movement
 */
class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Movement $movement */
        $movement = $this->resource;
        $values = Values::visible($request, $movement->location_id, $movement->company_id);

        return [
            'id' => $movement->id,
            'kind' => $movement->kind,
            'item' => Refs::item($movement->item),
            'company_id' => $movement->company_id,
            'location' => Refs::place($movement->location),
            'batch' => Refs::batch($movement->batch),
            'qty' => Qty::show($movement->qty),
            'uom' => Refs::uom($movement->uom),
            'uom_qty' => Qty::show($movement->uom_qty),
            'balance_after' => Qty::show($movement->balance_after),
            'value' => $values ? Values::money($movement->value_minor, $movement->currency) : null,
            'cost_variance' => $values ? Values::money($movement->cost_variance_minor, $movement->currency) : null,
            'source_type' => $movement->source_type,
            'source_id' => $movement->source_id,
            'flags' => array_values($movement->flags ?? []),
            'serials' => $this->whenLoaded('serials', fn () => $movement->serials->pluck('serial_number')->values()->all()),
            'created_by' => Refs::user($movement->creator),
            'occurred_at' => Refs::at($movement->occurred_at),
        ];
    }
}
