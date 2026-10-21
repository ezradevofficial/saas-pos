<?php

namespace App\Core\Marketplace\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * API-02 (OAuth), MKT-02: an app's authorization request, shown as the
 * consent screen (`core.marketplace.view`: anyone who may see the
 * marketplace sees what the app asks for; installing needs more).
 * Authorization code with PKCE only: `response_type=code` and an S256
 * `code_challenge` are required for every client, confidential or not.
 */
class AuthorizeAppRequest extends MarketplaceRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'redirect_uri' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'response_type' => ['required', 'string', Rule::in(['code'])],
            'scope' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'state' => ['sometimes', 'nullable', 'string', 'max:500'],
            // RFC 7636: BASE64URL(SHA256(verifier)), 43 characters; S256 only (never `plain`).
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code_challenge_method' => ['required', 'string', Rule::in(['S256'])],
        ];
    }

    /** @return array<string, string> the OAuth parameters, as the authorization server reads them */
    public function oauthParameters(): array
    {
        return array_filter([
            'client_id' => $this->validated('client_id'),
            'redirect_uri' => $this->validated('redirect_uri'),
            'response_type' => 'code',
            'scope' => $this->validated('scope'),
            'state' => $this->validated('state'),
            'code_challenge' => $this->validated('code_challenge'),
            'code_challenge_method' => 'S256',
        ], fn ($value) => $value !== null && $value !== '');
    }
}
