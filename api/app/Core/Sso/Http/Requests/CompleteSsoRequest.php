<?php

namespace App\Core\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** AUTH-11: the browser hands back the ticket from the callback and its binding secret. */
class CompleteSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:48', 'alpha_num:ascii'],
            'binding' => ['required', 'string', 'size:43', 'alpha_num:ascii'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** The session's label, as for a password sign-in. */
    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return mb_substr($name !== '' ? $name : (string) $this->userAgent(), 0, 100) ?: 'unknown';
    }
}
