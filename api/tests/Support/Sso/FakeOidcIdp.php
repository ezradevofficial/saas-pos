<?php

namespace Tests\Support\Sso;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * AUTH-11: a fake OIDC IdP behind Http::fake (no network): discovery, a
 * JWKS with an RSA key generated per process, and a token endpoint that
 * answers the ID token the test set ($idToken, or a closure of the token
 * request). Serves Entra (`{authority}/{directory}/v2.0`) and Google.
 */
class FakeOidcIdp
{
    public const DIRECTORY = '6f1c0b2e-3d4a-4b5c-8d9e-0a1b2c3d4e5f';

    public const CLIENT_ID = '0b7e1f2a-9c8d-4e3f-a1b2-c3d4e5f6a7b8';

    public const GOOGLE_CLIENT_ID = '123456789-abc.apps.googleusercontent.com';

    public const KID = 'test-key-1';

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $key = null;

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $otherKey = null;

    /** @var string|\Closure(Request): (string|null)|null */
    public mixed $idToken = null;

    /** @var list<array<string, mixed>> the token requests' form fields */
    public array $tokenRequests = [];

    /** @var list<array<string, mixed>> extra keys published in the JWKS */
    public array $extraKeys = [];

    public int $jwksFetches = 0;

    public static function entraIssuer(): string
    {
        return 'https://login.microsoftonline.com/'.self::DIRECTORY.'/v2.0';
    }

    /** @return array{private: string, n: string, e: string} */
    public static function key(): array
    {
        return self::$key ??= self::generate();
    }

    /** @return array{private: string, n: string, e: string} a key the IdP never published */
    public static function otherKey(): array
    {
        return self::$otherKey ??= self::generate();
    }

    public function fake(): self
    {
        $google = 'https://accounts.google.com';
        $entra = self::entraIssuer();

        Http::fake([
            $entra.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $entra,
                'authorization_endpoint' => 'https://login.microsoftonline.com/'.self::DIRECTORY.'/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.microsoftonline.com/'.self::DIRECTORY.'/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.microsoftonline.com/'.self::DIRECTORY.'/discovery/v2.0/keys',
            ]),
            $google.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $google,
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_endpoint' => 'https://oauth2.googleapis.com/token',
                'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            ]),
            'login.microsoftonline.com/*/discovery/v2.0/keys' => fn () => $this->jwksResponse(),
            'www.googleapis.com/oauth2/v3/certs' => fn () => $this->jwksResponse(),
            'login.microsoftonline.com/*/oauth2/v2.0/token' => fn (Request $request) => $this->tokenResponse($request),
            'oauth2.googleapis.com/token' => fn (Request $request) => $this->tokenResponse($request),
        ]);

        return $this;
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims, ?array $key = null, string $kid = self::KID, string $alg = 'RS256'): string
    {
        $key ??= self::key();

        return JWT::encode($claims, $key['private'], $alg, $kid);
    }

    /** Entra claims for $email with $nonce, valid now; $overrides replace or (null) remove. */
    public static function entraClaims(string $email, string $nonce, array $overrides = []): array
    {
        return self::claims([
            'iss' => self::entraIssuer(),
            'aud' => self::CLIENT_ID,
            'sub' => 'entra-subject-'.md5($email),
            'tid' => self::DIRECTORY,
            // The UPN: what a sign-in links by unless the email is marked verified (xms_edov).
            'preferred_username' => $email,
            'email' => $email,
            'name' => 'Amina Otieno',
            'nonce' => $nonce,
        ], $overrides);
    }

    public static function googleClaims(string $email, string $nonce, array $overrides = []): array
    {
        return self::claims([
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'azp' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-subject-'.md5($email),
            'email' => $email,
            'email_verified' => true,
            'hd' => substr($email, strpos($email, '@') + 1),
            'name' => 'Jean Mukendi',
            'nonce' => $nonce,
        ], $overrides);
    }

    private static function claims(array $claims, array $overrides): array
    {
        $now = time();
        $claims = [...$claims, 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600, ...$overrides];

        return array_filter($claims, fn ($value) => $value !== null);
    }

    private function jwksResponse()
    {
        $this->jwksFetches++;
        $key = self::key();

        return Http::response(['keys' => [
            ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => self::KID, 'n' => $key['n'], 'e' => $key['e']],
            ...$this->extraKeys,
        ]]);
    }

    private function tokenResponse(Request $request)
    {
        $this->tokenRequests[] = $request->data();
        $token = $this->idToken instanceof \Closure ? ($this->idToken)($request) : $this->idToken;

        return $token === null
            ? Http::response(['error' => 'invalid_grant'], 400)
            : Http::response(['access_token' => 'access', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $token]);
    }

    /** @return array{private: string, n: string, e: string} */
    private static function generate(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource)['rsa'];

        return [
            'private' => $private,
            'n' => rtrim(strtr(base64_encode($details['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['e']), '+/', '-_'), '='),
        ];
    }
}
