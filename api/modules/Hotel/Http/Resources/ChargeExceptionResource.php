<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\ChargeException;

/**
 * HOT-22: a room charge from a till that could not be posted.
 *
 * @mixin ChargeException
 */
class ChargeExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChargeException $e */
        $e = $this->resource;

        return [
            'id' => $e->id,
            'branch_id' => $e->branch_id,
            'source' => $e->source,
            'source_id' => $e->source_id,
            'reference' => $e->reference,
            'room_number' => $e->room_number,
            'currency' => $e->currency,
            'amount_minor' => (string) $e->amount_minor,
            'reason' => $e->reason,
            'status' => $e->status,
            'resolution' => $e->resolution,
            'resolved_folio_id' => $e->resolved_folio_id,
            'note' => $e->note,
            'resolved_at' => Refs::at($e->resolved_at),
            'created_at' => Refs::at($e->created_at),
        ];
    }
}
