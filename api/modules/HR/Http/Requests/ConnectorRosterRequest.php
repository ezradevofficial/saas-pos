<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET hr/connector/employees (HR-24, MKT-04): the active roster, by `company` and `location`. */
class ConnectorRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at each employee's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
