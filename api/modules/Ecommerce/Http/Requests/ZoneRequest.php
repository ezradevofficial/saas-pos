<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST ecommerce/stores/{store}/zones and PATCH ecommerce/zones/{zone} (ECOM-10): fees in minor units of the store currency. */
class ZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'areas' => ['sometimes', 'array', 'max:100'],
            'areas.*' => ['string', 'max:120'],
            'fee_minor' => [...$required, 'string', 'regex:/^\d{1,15}$/'],
            'free_over_minor' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}$/'],
            'min_order_minor' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}$/'],
            'eta' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:0,100000'],
        ];
    }
}
