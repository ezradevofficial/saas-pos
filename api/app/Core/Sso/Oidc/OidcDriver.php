<?php

namespace App\Core\Sso\Oidc;

/**
 * AUTH-11, ADR 023: what our Socialite drivers add to Socialite's own.
 *
 * - The endpoints come from the IdP's discovery document, not constants.
 * - `state`, `nonce` and the PKCE challenge come from our flow (the cache),
 *   never from a session: the API has none.
 * - The code exchange sends the flow's PKCE verifier.
 *
 * `user()` is never called: Socialite reads Graph or userinfo and does not
 * check the ID token. IdTokenValidator does.
 */
trait OidcDriver
{
    private string $authorizeEndpoint = '';

    private string $tokenEndpoint = '';

    private ?string $codeVerifier = null;

    public function useEndpoints(string $authorize, string $token): static
    {
        $this->authorizeEndpoint = $authorize;
        $this->tokenEndpoint = $token;

        return $this;
    }

    public function withCodeVerifier(string $verifier): static
    {
        $this->codeVerifier = $verifier;

        return $this;
    }

    /** The authorisation request URL: our state, plus the nonce and PKCE parameters given. */
    public function authorizationUrl(string $state, array $parameters): string
    {
        return $this->with($parameters)->getAuthUrl($state);
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->authorizeEndpoint, $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->tokenEndpoint;
    }

    protected function getTokenFields($code)
    {
        // The authorisation parameters (nonce, PKCE challenge) are not token fields.
        $this->parameters = [];

        return array_filter([...parent::getTokenFields($code), 'code_verifier' => $this->codeVerifier], fn ($value) => $value !== null);
    }
}
