<?php

namespace Modules\Pharmacy\Http\Requests;

/** POST pharmacy/prescriptions/{id}/cancel (PHA-14): `pharmacy.prescription.cancel` at its location; `{reason}`. */
class CancelPrescriptionRequest extends ShowPrescriptionRequest
{
    protected function needs(): ?string
    {
        return 'pharmacy.prescription.cancel';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
