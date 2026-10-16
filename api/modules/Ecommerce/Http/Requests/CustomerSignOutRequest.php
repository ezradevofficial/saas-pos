<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/auth/sign-out (ECOM-08): ends the customer's session (the bearer token); no body. */
class CustomerSignOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
