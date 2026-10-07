<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Rules\LoginAvailable;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Identity\Services\SignUp;
use App\Core\Identity\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** AUTH-01: open to anyone (rate limited); creates a new tenant. */
class SignUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');

        $this->merge([
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            // Left as typed when it cannot be read, so the format rule reports it.
            'phone' => is_string($phone) && trim($phone) !== ''
                ? (PhoneNumber::normalise($phone, is_string($this->input('country')) ? $this->input('country') : null) ?? $phone)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'prohibits:phone', 'string', 'email', 'max:255', new LoginAvailable],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:/^\+[1-9]\d{7,14}$/', new LoginAvailable],
            'password' => PasswordPolicy::rules(null),
            'country' => ['required', 'string', Rule::in(array_keys(SignUp::COUNTRIES))],
            'locale' => ['required', 'string', Rule::in(['en', 'fr'])],
            'business_name' => ['required', 'string', 'max:255'],
        ];
    }
}
