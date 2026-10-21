<?php

namespace App\Core\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-11: the IdP sends the browser back (OIDC: `state`, `code` or
 * `error`; SAML: `SAMLResponse`, `RelayState`). The state is the
 * credential; anything malformed fails the sign-in as a redirect, never a
 * JSON error the browser would show.
 */
class SsoCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function text(string $key, int $max): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }
}
