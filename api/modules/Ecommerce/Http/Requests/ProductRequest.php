<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST ecommerce/stores/{store}/products (items added, ECOM-02) and PATCH ecommerce/products/{product}. */
class ProductRequest extends FormRequest
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
            'item_ids' => [...($create ? ['required'] : ['prohibited']), 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['uuid', 'distinct', Rule::exists('items', 'id')->whereNull('archived_at')],
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]([a-z0-9-]{0,118}[a-z0-9])?$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'published' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:0,100000'],
            'track_stock' => ['sometimes', 'boolean'],
            'in_stock' => ['sometimes', 'boolean'],
        ];
    }
}
