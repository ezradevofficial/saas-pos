<?php

namespace App\Core\Identity\Pin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** AUTH-06: DELETE me/pos-pin, confirming with the password. */
class RemoveMyPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255']];
    }
}
