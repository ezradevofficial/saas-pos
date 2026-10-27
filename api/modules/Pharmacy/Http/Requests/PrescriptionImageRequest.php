<?php

namespace Modules\Pharmacy\Http\Requests;

use Modules\Pharmacy\Prescriptions\Prescriptions;

/**
 * POST pharmacy/prescriptions/{id}/image (PHA-15): `pharmacy.prescription.create`
 * at its location; multipart `file` (JPEG, PNG, WebP or PDF, up to 10 MB).
 */
class PrescriptionImageRequest extends ShowPrescriptionRequest
{
    protected function needs(): ?string
    {
        return 'pharmacy.prescription.create';
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.Prescriptions::MAX_KB, 'mimetypes:'.implode(',', array_keys(Prescriptions::MIMES))]];
    }
}
