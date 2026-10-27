<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-16, PHA-17, PHA-24, PHA-26: a dispensed sale line, who approved it,
 * the prescription it was dispensed against (name of the patient only) and
 * what the server found.
 *
 * @mixin Dispense
 */
class DispenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Dispense $dispense */
        $dispense = $this->resource;
        $prescription = $dispense->prescription;

        return [
            'id' => $dispense->id,
            'sale_id' => $dispense->sale_id,
            'receipt_number' => $dispense->receipt_number,
            'item' => Refs::item($dispense->item),
            'class' => $dispense->class,
            'qty' => Qty::show($dispense->qty),
            'location' => Refs::place($dispense->location),
            'customer' => Refs::place($dispense->customer),
            'prescription' => $prescription === null ? null : [
                'id' => $prescription->id,
                'patient_name' => $prescription->patient_name,
                'prescriber_name' => $prescription->prescriber_name,
                'prescriber_licence' => $prescription->prescriber_licence,
                'prescribed_on' => $prescription->prescribed_on->toDateString(),
            ],
            'pharmacist' => Refs::user($dispense->pharmacist),
            'cashier' => Refs::user($dispense->cashier),
            'approval_mode' => $dispense->approval_mode,
            'flags' => $dispense->flags,
            'review_status' => $dispense->review_status,
            'review_note' => $dispense->review_note,
            'reviewed_at' => Refs::at($dispense->reviewed_at),
            'dispensed_at' => Refs::at($dispense->dispensed_at),
        ];
    }
}
