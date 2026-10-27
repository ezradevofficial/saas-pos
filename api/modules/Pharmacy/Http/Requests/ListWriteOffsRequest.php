<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Http\Lists\WriteOffList;
use Modules\Pharmacy\Models\WriteOff;

/** GET pharmacy/write-offs (PHA-12): `pharmacy.writeoff.view`; write-offs at the reader's locations; `?status=`, `?from=`, `?to=`, `?search=` (number or note). */
class ListWriteOffsRequest extends FormRequest
{
    use ListsPharmacy;

    public function list(): ListDefinition
    {
        return new WriteOffList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.writeoff.view';
    }

    protected function statuses(): array
    {
        return WriteOff::STATUSES;
    }
}
