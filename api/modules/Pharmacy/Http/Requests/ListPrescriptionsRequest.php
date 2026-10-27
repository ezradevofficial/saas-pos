<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Http\Lists\PrescriptionList;

/**
 * GET pharmacy/prescriptions (PHA-14, PHA-28): `pharmacy.prescription.view`;
 * prescriptions (personal data) at the reader's locations; `?status=` (valid,
 * cancelled), `?from=`/`?to=` (prescribed on), `?search=` (patient, prescriber,
 * licence or facility).
 */
class ListPrescriptionsRequest extends FormRequest
{
    use ListsPharmacy;

    public function list(): ListDefinition
    {
        return new PrescriptionList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.prescription.view';
    }

    protected function statuses(): array
    {
        return ['valid', 'cancelled'];
    }
}
