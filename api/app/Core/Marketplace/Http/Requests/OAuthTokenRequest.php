<?php

namespace App\Core\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API-02 (OAuth): the token endpoint (public: the client authenticates
 * with its id, its secret when it has one, and the PKCE verifier). Only
 * the authorization code and refresh token grants; the authorization
 * server checks the rest and answers in the OAuth error format.
 */
class OAuthTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grant_type' => ['required', 'string', Rule::in(['authorization_code', 'refresh_token'])],
        ];
    }
}
