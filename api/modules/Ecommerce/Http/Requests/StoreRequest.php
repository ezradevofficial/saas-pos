<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ecommerce\Models\Store;

/** POST and PATCH ecommerce/stores (ECOM-01): a store's settings; references are checked against the company by Stores (under RLS). */
class StoreRequest extends FormRequest
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
            'company_id' => [...($this->isMethod('POST') ? ['required'] : ['prohibited']), 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'branch_id' => [...$required, 'uuid', Rule::exists('branches', 'id')->whereNull('archived_at')],
            'location_id' => [...$required, 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')],
            'handle' => [...$required, 'string', 'max:60', 'regex:/^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$/'],
            'name' => [...$required, 'string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::in(Store::STATUSES)],
            'currency' => [...$required, 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'price_list_id' => [...$required, 'uuid', Rule::exists('price_lists', 'id')->whereNull('archived_at')],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'pickup_location_ids' => ['sometimes', 'array', 'max:20'],
            'pickup_location_ids.*' => ['uuid', 'distinct', Rule::exists('locations', 'id')->whereNull('archived_at')],
            'min_order_minor' => ['sometimes', 'string', 'regex:/^\d{1,15}$/'],
            'reservation_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'safety_buffer' => ['sometimes', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'delivery_tax_code_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tax_codes', 'id')],
            'payment_methods' => ['sometimes', 'array', 'max:5'],
            'payment_methods.*.kind' => ['required', 'string', 'distinct', Rule::in(array_keys(Store::PAYMENT_KINDS))],
            'payment_methods.*.payment_method_id' => ['required', 'uuid', Rule::exists('payment_methods', 'id')],
        ];
    }
}
