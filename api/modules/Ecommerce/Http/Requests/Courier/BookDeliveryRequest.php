<?php

namespace Modules\Ecommerce\Http\Requests\Courier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST ecommerce/courier/deliveries/{ecom_delivery}/booking (MKT-04,
 * ECOM-27): the courier books the delivery. `reference` is the client's
 * own reference of the booking (sent again, the booking answers the
 * same); `tracking_reference` and `tracking_url` (HTTPS) are the courier's.
 */
class BookDeliveryRequest extends FormRequest
{
    public const REFERENCE = ['required', 'string', 'min:1', 'max:120', 'regex:/^[A-Za-z0-9._:\-]+$/'];

    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the delivery's location (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => self::REFERENCE,
            'tracking_reference' => ['required', 'string', 'max:120'],
            'tracking_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:https'],
        ];
    }
}
