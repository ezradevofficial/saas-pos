<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST pharmacy/prescriptions (PHA-14): `pharmacy.prescription.create` at the
 * location; `{location_id, prescriber_name, prescriber_licence, facility,
 * patient_name, prescribed_on, patient_phone?, patient_birth_date?,
 * patient_party_id?, notes?}`. The licence number's format is not checked
 * (to confirm, docs/modules/pharmacy.md); the date cannot be in the future.
 */
class StorePrescriptionRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.prescription.create';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.prescription.create'];
    }

    public function rules(): array
    {
        return [
            'location_id' => PharmacyRules::location(),
            'prescriber_name' => ['required', 'string', 'max:150'],
            'prescriber_licence' => ['required', 'string', 'max:60'],
            'facility' => ['required', 'string', 'max:150'],
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'patient_birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'patient_party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'prescribed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
