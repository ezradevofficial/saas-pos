<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Notifications\Sms\SmsAvailability;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * AUTH-04: a phone number login needs a code by SMS; in email-only mode
     * it is refused for every phone number alike (whether or not an account
     * uses it), so the answer still says nothing about the login.
     */
    protected function prepareForValidation(): void
    {
        $login = $this->input('login');

        if (is_string($login) && trim($login) !== '' && ! str_contains($login, '@')) {
            app(SmsAvailability::class)->assertAvailable('login');
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
        ];
    }
}
