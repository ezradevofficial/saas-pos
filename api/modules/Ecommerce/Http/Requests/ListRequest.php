<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The module's simple lists: `?store=`, `?status=`, `?search=`, `?company=`, `?rider=`, pages. */
class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'store' => ['sometimes', 'uuid', Rule::exists('ecom_stores', 'id')],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'rider' => ['sometimes', 'uuid', Rule::exists('ecom_riders', 'id')],
            'status' => ['sometimes', 'string', 'max:20'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
