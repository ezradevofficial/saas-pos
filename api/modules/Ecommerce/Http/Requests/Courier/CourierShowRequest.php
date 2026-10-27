<?php

namespace Modules\Ecommerce\Http\Requests\Courier;

use Illuminate\Foundation\Http\FormRequest;

/** GET ecommerce/courier/orders/{ecom_order} (MKT-04, ECOM-27): an order to deliver and its delivery. */
class CourierShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the order's location (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
