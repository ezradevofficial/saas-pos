<?php

namespace App\Core\Sso\Oidc;

use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\SsoAssertion;
use App\Core\Sso\SsoFailure;
use Closure;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * AUTH-11, ADR 023: an OIDC ID token, checked in full:
 *
 * - signature: RS256 only, against the IdP's JWKS (RSA signing keys only),
 *   fetched again once when the token names an unknown key;
 * - `exp`, `nbf` and `iat` with LEEWAY seconds;
 * - `iss` one of the connection's issuers; `aud` the client id (with
 *   several audiences, `azp` must be the client id);
 * - `nonce` the flow's;
 * - an email: Google's verified (`email_verified`) and of a Workspace
 *   (`hd`, the connection's hosted domain when one is set); Entra's
 *   `email`, else `preferred_username`;
 * - MFA when `amr` holds `mfa`.
 */
class IdTokenValidator
{
    public const LEEWAY = 60;

    public const ALGORITHM = 'RS256';

    /**
     * @param  Closure(bool): array<string, mixed>  $jwks  the key set; true fetches it again
     *
     * @throws SsoFailure
     */
    public function validate(SsoConnection $connection, string $idToken, string $nonce, Closure $jwks): SsoAssertion
    {
        $header = $this->header($idToken);

        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new SsoFailure('signature_invalid', 'Only RS256 ID tokens are accepted.');
        }

        $claims = $this->decode($idToken, $jwks(false), $header);

        if ($claims === null) {
            // A key we have not seen: the IdP may have rotated its keys.
            $claims = $this->decode($idToken, $jwks(true), $header) ?? throw new SsoFailure('signature_invalid', 'No key matches the token.');
        }

        if (! in_array($claims->iss ?? null, OidcClient::issuers($connection), true)) {
            throw new SsoFailure('issuer_mismatch');
        }

        $clientId = (string) $connection->setting('client_id');
        $audiences = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];

        if (! in_array($clientId, $audiences, true) || (count($audiences) > 1 && ($claims->azp ?? null) !== $clientId)) {
            throw new SsoFailure('audience_mismatch');
        }

        if (! isset($claims->exp, $claims->iat)) {
            throw new SsoFailure('token_invalid', 'The token has no exp or iat.');
        }

        if (! is_string($claims->nonce ?? null) || ! hash_equals($nonce, $claims->nonce)) {
            throw new SsoFailure('nonce_mismatch');
        }

        $subject = $claims->sub ?? null;

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new SsoFailure('token_invalid', 'The token has no subject.');
        }

        $email = $this->email($connection, $claims);
        $amr = is_array($claims->amr ?? null) ? $claims->amr : [];
        $name = is_string($claims->name ?? null) ? Str::limit(trim($claims->name), 255, '') : null;

        return new SsoAssertion($subject, $email, $name === '' ? null : $name, in_array('mfa', $amr, true));
    }

    private function email(SsoConnection $connection, stdClass $claims): string
    {
        if ($connection->provider === SsoConnection::GOOGLE) {
            if (($claims->email_verified ?? null) !== true && ($claims->email_verified ?? null) !== 'true') {
                throw new SsoFailure('email_unverified');
            }

            $hd = $claims->hd ?? null;
            $required = $connection->setting('hosted_domain');

            if (! is_string($hd) || $hd === '' || (is_string($required) && $required !== '' && Str::lower($hd) !== Str::lower($required))) {
                throw new SsoFailure('hosted_domain_mismatch');
            }

            $email = $claims->email ?? null;
        } else {
            $email = $claims->email ?? $claims->preferred_username ?? null;
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new SsoFailure('email_missing');
        }

        return Str::lower(trim($email));
    }

    /** @return array<string, mixed> */
    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new SsoFailure('token_invalid', 'Not a JWT.');
        }

        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);

        if (! is_array($header)) {
            throw new SsoFailure('token_invalid', 'Bad JWT header.');
        }

        return $header;
    }

    /**
     * The verified claims, or null when no key of $jwks has the token's `kid`.
     *
     * @param  array<string, mixed>  $jwks
     * @param  array<string, mixed>  $header
     */
    private function decode(string $jwt, array $jwks, array $header): ?stdClass
    {
        // RSA signing keys only, whatever else the set holds.
        $keys = array_values(array_filter((array) ($jwks['keys'] ?? []), fn ($key) => is_array($key)
            && ($key['kty'] ?? null) === 'RSA'
            && in_array($key['alg'] ?? self::ALGORITHM, [self::ALGORITHM], true)
            && in_array($key['use'] ?? 'sig', ['sig'], true)));

        $kid = $header['kid'] ?? null;

        if ($keys === [] || ($kid !== null && ! in_array($kid, array_column($keys, 'kid'), true))) {
            return null;
        }

        $previous = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            $parsed = JWK::parseKeySet(['keys' => $keys], self::ALGORITHM);

            // A token without a kid is tried against the only key, never several.
            if ($kid === null) {
                if (count($parsed) !== 1) {
                    throw new SsoFailure('signature_invalid', 'The token names no key.');
                }

                $parsed = reset($parsed);
            }

            return JWT::decode($jwt, $parsed);
        } catch (ExpiredException) {
            throw new SsoFailure('expired');
        } catch (BeforeValidException) {
            throw new SsoFailure('token_invalid', 'The token is not valid yet.');
        } catch (SignatureInvalidException) {
            throw new SsoFailure('signature_invalid');
        } catch (SsoFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SsoFailure('token_invalid', $e->getMessage());
        } finally {
            JWT::$leeway = $previous;
        }
    }
}
