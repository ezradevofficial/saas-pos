<?php

namespace App\Core\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant's password policy (AUTH-02) is applied by the controller once
 * the login names a tenant.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
