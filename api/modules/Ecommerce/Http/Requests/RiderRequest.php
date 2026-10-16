<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST and PATCH ecommerce/riders (ECOM-26): a user of the tenant, optionally an HR employee. */
class RiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'company_id' => [...($create ? ['required'] : ['prohibited']), 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'user_id' => [...($create ? ['required'] : ['prohibited']), 'uuid', Rule::exists('users', 'id')],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => [...($create ? ['sometimes'] : ['sometimes', 'required']), 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vehicle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
