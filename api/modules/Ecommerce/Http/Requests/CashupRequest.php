<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST ecommerce/cashups (ECOM-30): a rider's cash counted in one currency. */
class CashupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'rider_id' => ['required', 'uuid', Rule::exists('ecom_riders', 'id')],
            'currency' => ['required', 'string', 'size:3'],
            'counted_minor' => ['required', 'string', 'regex:/^\d{1,15}$/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
