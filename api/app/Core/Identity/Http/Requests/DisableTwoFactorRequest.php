<?php

namespace App\Core\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
