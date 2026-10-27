<?php

namespace Modules\Ecommerce\Http\Requests\Courier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST ecommerce/courier/deliveries/{ecom_delivery}/status (MKT-04,
 * ECOM-28): `picked_up`, `delivered` (with the customer's 6-digit delivery
 * code) or `failed` (with a reason), when it happened, and the client's
 * own `reference` of the update (sent again, it answers the same).
 */
class CourierStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the delivery's location (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => BookDeliveryRequest::REFERENCE,
            'status' => ['required', 'string', Rule::in(['picked_up', 'delivered', 'failed'])],
            'occurred_at' => ['required', 'date'],
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'reason' => ['required_if:status,failed', 'nullable', 'string', 'max:500'],
        ];
    }
}
