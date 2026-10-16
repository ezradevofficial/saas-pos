<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET shop/{store}/products (ECOM-05): `?category=`, `?search=`, `?featured=1`, pages. */
class ShopProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,60'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
