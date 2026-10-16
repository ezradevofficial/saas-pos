<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/auth/verify (ECOM-08). */
class CustomerVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'regex:/^\d{6}$/']];
    }
}
