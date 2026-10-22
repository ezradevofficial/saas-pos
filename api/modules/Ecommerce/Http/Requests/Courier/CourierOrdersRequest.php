<?php

namespace Modules\Ecommerce\Http\Requests\Courier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET ecommerce/courier/orders (MKT-04, ECOM-27): `status` is `open` (the
 * default: orders waiting for a courier, and the ones handed to you still
 * on their way), `ready` (waiting for a courier), `handed` (handed to you,
 * whatever their state) or `all` (both).
 */
class CourierOrdersRequest extends FormRequest
{
    public const STATUSES = ['open', 'ready', 'handed', 'all'];

    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at each order's location (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
