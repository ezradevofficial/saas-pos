<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/package-types/{id}: `salon.service.manage` at the package type's company. */
class UpdatePackageTypeRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'kind' => ['sometimes', 'required', 'string', 'in:package,membership'],
            'sessions' => ['sometimes', 'required', 'integer', 'between:1,200'],
            'validity_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => ['uuid', Rule::exists('salon_services', 'id')],
        ];
    }
}
