<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/orders (ECOM-11 to ECOM-15): the cart, how to pay, who and where, and the total the customer saw. */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        // ECOM-44: order messages on WhatsApp, only when the customer ticks it.
        return [...CartRules::cart(), ...CartRules::checkout(['mpesa', 'card', 'cod']), 'whatsapp_opt_in' => ['sometimes', 'boolean']];
    }
}
