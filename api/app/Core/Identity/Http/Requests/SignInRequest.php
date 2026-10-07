<?php

namespace App\Core\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** The session's label: the device name sent, else the user agent (100 characters). */
    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return mb_substr($name !== '' ? $name : (string) $this->userAgent(), 0, 100) ?: 'unknown';
    }
}
