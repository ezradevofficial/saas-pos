<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/package-types (SAL-18): `salon.service.manage` at the company. */
class StorePackageTypeRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')],

            'name' => ['required', 'string', 'max:120'],
            'kind' => ['sometimes', 'required', 'string', 'in:package,membership'],
            'sessions' => ['required', 'integer', 'between:1,200'],
            'validity_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => ['uuid', Rule::exists('salon_services', 'id')],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atCompany($this->input('company_id'));
    }
}
