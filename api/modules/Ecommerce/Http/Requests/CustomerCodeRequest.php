<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/auth/code (ECOM-08): a phone number or email address. */
class CustomerCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['contact' => ['required', 'string', 'max:190']];
    }
}
