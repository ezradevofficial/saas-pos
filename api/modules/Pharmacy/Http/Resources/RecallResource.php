<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Recall;

/**
 * PHA-22: a recall of one batch of an item and how many lots it blocks.
 *
 * @mixin Recall
 */
class RecallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Recall $recall */
        $recall = $this->resource;

        return [
            'id' => $recall->id,
            'item' => Refs::item($recall->item),
            'batch_number' => $recall->batch_number,
            'reference' => $recall->reference,
            'reason' => $recall->reason,
            'status' => $recall->status,
            'lots_count' => $recall->lots_count ?? $recall->lots()->count(),
            'issued_by' => Refs::user($recall->issuer),
            'issued_at' => Refs::at($recall->issued_at),
            'lifted_by' => Refs::user($recall->lifter),
            'lifted_at' => Refs::at($recall->lifted_at),
            'lift_reason' => $recall->lift_reason,
        ];
    }
}
