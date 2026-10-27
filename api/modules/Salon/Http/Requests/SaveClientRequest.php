<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** PUT salon/clients/{party} (SAL-25): `salon.client.manage`; allergies and formulas need `salon.client.notes`. */
class SaveClientRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'preferred_staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
            'preferences' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'formulas' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atCompany($this->input('company_id'));
    }
}
