<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Prescription;

/**
 * PHA-14, PHA-15, PHA-28: a prescription (personal data: shown only to
 * holders of `pharmacy.prescription.view`, which the list and show requests
 * require). The image is described, never linked here: the link is asked for
 * (and audited) at `image-link`.
 *
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Prescription $prescription */
        $prescription = $this->resource;

        return [
            'id' => $prescription->id,
            'status' => $prescription->status,
            'source' => $prescription->source,
            'company' => Refs::place($prescription->company),
            'branch' => Refs::place($prescription->branch),
            'location' => Refs::place($prescription->location),
            'prescriber_name' => $prescription->prescriber_name,
            'prescriber_licence' => $prescription->prescriber_licence,
            'facility' => $prescription->facility,
            'patient_name' => $prescription->patient_name,
            'patient_phone' => $prescription->patient_phone,
            'patient_birth_date' => $prescription->patient_birth_date?->toDateString(),
            'patient' => Refs::place($prescription->patient),
            'prescribed_on' => $prescription->prescribed_on->toDateString(),
            'notes' => $prescription->notes,
            'sale_id' => $prescription->sale_id,
            'image' => $prescription->hasImage() ? ['name' => $prescription->image_name, 'mime' => $prescription->image_mime, 'size' => $prescription->image_size] : null,
            'cancelled_at' => Refs::at($prescription->cancelled_at),
            'cancel_reason' => $prescription->cancel_reason,
            'created_by' => Refs::user($prescription->creator),
            'created_at' => Refs::at($prescription->created_at),
        ];
    }
}
