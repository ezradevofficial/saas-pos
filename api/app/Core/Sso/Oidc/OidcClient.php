<?php

namespace App\Core\Sso\Oidc;

use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\SsoAssertion;
use App\Core\Sso\SsoFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * AUTH-11, ADR 023: OIDC with Microsoft Entra ID and Google Workspace.
 *
 * The issuer is fixed per provider: Entra `{authority}/{directory id}/v2.0`
 * (a named directory, never `common`), Google `{issuer}`. Its discovery
 * document gives the endpoints and the JWKS; both are cached for an hour.
 * The authorisation request carries state, nonce and an S256 PKCE
 * challenge; the code is exchanged with the verifier; the ID token is then
 * checked by IdTokenValidator.
 */
class OidcClient
{
    public const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly Request $request,
        private readonly IdTokenValidator $validator,
    ) {}

    /** The issuers an ID token of $connection may name (Google: with and without the scheme). */
    public static function issuers(SsoConnection $connection): array
    {
        if ($connection->provider === SsoConnection::ENTRA) {
            return [rtrim((string) config('sso.entra.authority'), '/').'/'.$connection->setting('directory_id').'/v2.0'];
        }

        $issuer = rtrim((string) config('sso.google.issuer'), '/');

        return $issuer === 'https://accounts.google.com' ? [$issuer, 'accounts.google.com'] : [$issuer];
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** The IdP's sign-in URL for this flow. */
    public function authorizationUrl(SsoConnection $connection, string $state, string $nonce, string $verifier): string
    {
        $parameters = ['nonce' => $nonce, 'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256'];

        if ($connection->provider === SsoConnection::GOOGLE && is_string($connection->setting('hosted_domain'))) {
            $parameters['hd'] = $connection->setting('hosted_domain');
        }

        return $this->driver($connection)->authorizationUrl($state, $parameters);
    }

    /**
     * Exchange the code (with the PKCE verifier) and check the ID token.
     *
     * @throws SsoFailure
     */
    public function complete(SsoConnection $connection, string $code, string $verifier, string $nonce): SsoAssertion
    {
        try {
            $response = $this->driver($connection)->withCodeVerifier($verifier)->getAccessTokenResponse($code);
        } catch (SsoFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new SsoFailure('token_exchange_failed');
        }

        $idToken = is_array($response) ? ($response['id_token'] ?? null) : null;

        if (! is_string($idToken) || $idToken === '') {
            throw new SsoFailure('token_exchange_failed', 'No ID token in the token response.');
        }

        return $this->validator->validate($connection, $idToken, $nonce, fn (bool $refresh) => $this->jwks($connection, $refresh));
    }

    /** @return array<string, mixed> the discovery document, its issuer checked */
    public function discovery(SsoConnection $connection): array
    {
        $issuer = self::issuers($connection)[0];
        $url = $issuer.'/.well-known/openid-configuration';

        $document = Cache::remember('sso:oidc:discovery:'.sha1($url), self::CACHE_SECONDS, function () use ($url) {
            $response = Http::timeout(10)->acceptJson()->get($url);

            if (! $response->successful() || ! is_array($response->json())) {
                throw new SsoFailure('idp_error', "Discovery failed: {$url}");
            }

            return $response->json();
        });

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! is_string($document[$key] ?? null) || ! str_starts_with($document[$key], 'http')) {
                throw new SsoFailure('idp_error', "Discovery has no {$key}.");
            }
        }

        if (($document['issuer'] ?? null) !== $issuer) {
            throw new SsoFailure('issuer_mismatch', 'The discovery document names another issuer.');
        }

        return $document;
    }

    /** @return array<string, mixed> the JWKS, fetched again when $refresh */
    public function jwks(SsoConnection $connection, bool $refresh = false): array
    {
        $url = (string) $this->discovery($connection)['jwks_uri'];
        $key = 'sso:oidc:jwks:'.sha1($url);

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($url) {
            $response = Http::timeout(10)->acceptJson()->get($url);

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new SsoFailure('idp_error', "JWKS fetch failed: {$url}");
            }

            return $response->json();
        });
    }

    private function driver(SsoConnection $connection): EntraDriver|GoogleDriver
    {
        $document = $this->discovery($connection);
        $class = $connection->provider === SsoConnection::ENTRA ? EntraDriver::class : GoogleDriver::class;

        /** @var EntraDriver|GoogleDriver&AbstractProvider $driver */
        $driver = new $class($this->request, (string) $connection->setting('client_id'), (string) $connection->client_secret, SsoConnection::oidcRedirectUri());

        return $driver
            ->useEndpoints($document['authorization_endpoint'], $document['token_endpoint'])
            ->setHttpClient(Http::timeout(10)->buildClient());
    }
}
