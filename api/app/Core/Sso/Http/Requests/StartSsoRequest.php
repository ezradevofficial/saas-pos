<?php

namespace App\Core\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-11: start a single sign-on, before anyone is signed in (rate
 * limited): by email (the shared sign-in page) or the page's host (a
 * tenant subdomain or custom domain), optionally naming a connection.
 */
class StartSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $email = mb_strtolower(trim($this->input('email')));
            $this->merge(['email' => $email === '' ? null : $email]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_without:host', 'string', 'email', 'max:255'],
            'host' => ['nullable', 'required_without:email', 'string', 'max:255'],
            'connection_id' => ['nullable', 'uuid'],
            'origin' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => __('sso.attributes.email')];
    }
}
