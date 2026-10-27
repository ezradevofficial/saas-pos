<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Http\Lists\RegisterList;
use Modules\Pharmacy\Models\RegisterEntry;

/**
 * GET pharmacy/register (PHA-18, PHA-24): `pharmacy.register.view`; entries
 * of the controlled-drug register at the reader's locations; `?item_id=`,
 * `?kind=`, `?from=`/`?to=` (calendar days), `?search=` (batch, patient or
 * prescriber). Entry order, oldest first.
 */
class ListRegisterRequest extends FormRequest
{
    use ListsPharmacy;

    public function list(): ListDefinition
    {
        return new RegisterList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.register.view';
    }

    protected function extraRules(): array
    {
        return [
            'item_id' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'kind' => ['sometimes', 'string', Rule::in(RegisterEntry::KINDS)],
        ];
    }
}
