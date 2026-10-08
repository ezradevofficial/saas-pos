<?php

namespace App\Core\Identity\Pin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-06: PUT me/pos-pin, the signed-in user sets their own POS PIN (and
 * optionally staff card), confirming with their password.
 */
class UpdateMyPinRequest extends FormRequest
{
    use ValidatesNewPin;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255'], ...$this->newPinRules()];
    }
}
