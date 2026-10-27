<?php

namespace Modules\Pharmacy\Http\Requests;

/** GET pharmacy/prescriptions/{id}/image-link (PHA-15): `pharmacy.prescription.image` at its location; a signed link bound to the user. */
class PrescriptionLinkRequest extends ShowPrescriptionRequest
{
    protected function needs(): ?string
    {
        return 'pharmacy.prescription.image';
    }
}
