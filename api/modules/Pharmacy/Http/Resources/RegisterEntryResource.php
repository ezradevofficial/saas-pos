<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-18: one entry of the controlled-drug register, with its running balance.
 *
 * @mixin RegisterEntry
 */
class RegisterEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RegisterEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'entry_no' => $entry->entry_no,
            'kind' => $entry->kind,
            'item' => Refs::item($entry->item),
            'location' => Refs::place($entry->location),
            'qty' => Qty::show($entry->qty),
            'balance' => Qty::show($entry->balance),
            'batch_number' => $entry->batch_number,
            'expires_on' => $entry->expires_on?->toDateString(),
            'prescription_id' => $entry->prescription_id,
            'prescriber_name' => $entry->prescriber_name,
            'prescriber_licence' => $entry->prescriber_licence,
            'patient_name' => $entry->patient_name,
            'pharmacist' => Refs::user($entry->pharmacist),
            'recorded_by' => Refs::user($entry->recorder),
            'source_type' => $entry->source_type,
            'reason' => $entry->reason,
            'occurred_at' => Refs::at($entry->occurred_at),
        ];
    }
}
