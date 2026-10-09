<?php

namespace App\Core\Branding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-04, BR-05: public lookups by host, before anyone signs in: the
 * sign-in page's branding (`host`) and the TLS ask (`domain`). Anyone
 * may ask; the answer holds only public branding, or nothing.
 */
class PublicHostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host' => ['sometimes', 'nullable', 'string', 'max:260'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:260'],
        ];
    }
}
