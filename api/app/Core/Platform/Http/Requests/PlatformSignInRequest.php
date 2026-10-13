<?php

namespace App\Core\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Platform staff sign-in: email and password (ADR 013). */
class PlatformSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
