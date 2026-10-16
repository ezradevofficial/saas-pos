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
        return [...CartRules::cart(), ...CartRules::checkout(['mpesa', 'card', 'cod'])];
    }
}
