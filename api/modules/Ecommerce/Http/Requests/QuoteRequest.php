<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/quote and POST ecommerce/stores/{store}/quote (ECOM-09): a cart for the server to price. */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return CartRules::cart();
    }
}
