<?php

namespace App\Core\Sso\Saml;

use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\SsoAssertion;
use App\Core\Sso\SsoFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Throwable;

/**
 * AUTH-11, ADR 023: SAML 2.0, SP-initiated only, checked by php-saml in
 * strict mode:
 *
 * - the response or the assertion signed by one of the connection's
 *   certificates (unsigned responses fail; SHA-1 refused);
 * - the IdP entity id as issuer; our SP entity id as audience;
 * - Destination and Recipient the connection's ACS URL;
 * - NotBefore / NotOnOrAfter with php-saml's 180-second skew;
 * - InResponseTo the flow's AuthnRequest id;
 * - each assertion id used once (cache, until it expires).
 *
 * The library compares Destination and Recipient with "the current URL",
 * which it reads from PHP globals: they are pointed at the configured ACS
 * URL for the check only, then restored.
 */
class SamlClient
{
    /** AuthnContextClassRef values (and Entra's authnmethodsreferences) that mean multi-factor. */
    public const MFA_CONTEXTS = [
        'http://schemas.microsoft.com/claims/multipleauthn',
        'https://refeds.org/profile/mfa',
        'urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorContract',
        'urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorUnregistered',
        'urn:oasis:names:tc:SAML:2.0:ac:classes:TimeSyncToken',
    ];

    public const EMAIL_ATTRIBUTES = [
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'email',
        'mail',
        'urn:oid:0.9.2342.19200300.100.1.3',
    ];

    public const NAME_ATTRIBUTES = [
        'http://schemas.microsoft.com/identity/claims/displayname',
        'displayName',
        'name',
        'urn:oid:2.16.840.1.113730.3.1.241',
    ];

    public const AUTHN_METHODS_ATTRIBUTE = 'http://schemas.microsoft.com/claims/authnmethodsreferences';

    /** The replay set keeps an assertion id at least this long. */
    public const REPLAY_MIN_SECONDS = 600;

    /** @throws SsoFailure when the connection's IdP settings are incomplete */
    public function settings(SsoConnection $connection): Settings
    {
        try {
            return $this->buildSettings($connection);
        } catch (Throwable) {
            throw new SsoFailure('connection_unavailable', 'The SAML settings are incomplete.');
        }
    }

    private function buildSettings(SsoConnection $connection): Settings
    {
        $certificates = array_values(array_filter((array) $connection->setting('idp_certificates'), 'is_string'));

        return new Settings([
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $connection->spEntityId(),
                'assertionConsumerService' => ['url' => $connection->acsUrl(), 'binding' => Constants::BINDING_HTTP_POST],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
            ],
            'idp' => [
                'entityId' => (string) $connection->setting('idp_entity_id'),
                'singleSignOnService' => ['url' => (string) $connection->setting('idp_sso_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => $certificates[0] ?? '',
                'x509certMulti' => ['signing' => $certificates],
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'wantAssertionsSigned' => false,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                // No requested context: the IdP chooses, MFA included.
                'requestedAuthnContext' => false,
                'wantXMLValidation' => true,
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'rejectDeprecatedAlgorithm' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ]);
    }

    /** @return array{url: string, request_id: string} the IdP sign-in URL (HTTP-Redirect) and the request id */
    public function authnRequest(SsoConnection $connection, string $relayState): array
    {
        $request = new AuthnRequest($this->settings($connection));
        $url = (string) $connection->setting('idp_sso_url');

        return [
            'url' => $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['SAMLRequest' => $request->getRequest(), 'RelayState' => $relayState]),
            'request_id' => $request->getId(),
        ];
    }

    /**
     * @throws SsoFailure
     */
    public function validate(SsoConnection $connection, string $encoded, string $requestId): SsoAssertion
    {
        $settings = $this->settings($connection);

        try {
            $response = new SamlResponse($settings, $encoded);
        } catch (Throwable) {
            throw new SsoFailure('saml_invalid', 'The SAML response could not be read.');
        }

        $valid = $this->atAcs($connection, fn () => $response->isValid($requestId));

        if (! $valid) {
            throw self::failureFor($response->getErrorException());
        }

        try {
            $assertionId = (string) $response->getAssertionId();
            $nameId = trim((string) $response->getNameId());
            $attributes = $response->getAttributes();
            $contexts = $response->authnContextClassRefs();
        } catch (Throwable) {
            throw new SsoFailure('saml_invalid');
        }

        if ($assertionId === '' || $nameId === '' || strlen($nameId) > 255) {
            throw new SsoFailure('saml_invalid', 'No assertion id or NameID.');
        }

        // Replay: an assertion id is accepted once, remembered until it expires.
        $expires = (int) ($response->getAssertionNotOnOrAfter() ?: 0);
        $ttl = max(self::REPLAY_MIN_SECONDS, $expires - time() + Constants::ALLOWED_CLOCK_DRIFT);

        if (! Cache::add('sso:saml:assertion:'.$connection->id.':'.hash('sha256', $assertionId), true, $ttl)) {
            throw new SsoFailure('replayed');
        }

        $email = $this->email($nameId, $response->getNameIdFormat(), $attributes);
        $name = self::first($attributes, self::NAME_ATTRIBUTES);
        $methods = array_map('strval', (array) ($attributes[self::AUTHN_METHODS_ATTRIBUTE] ?? []));
        $mfa = array_intersect([...$contexts, ...$methods], self::MFA_CONTEXTS) !== [];

        return new SsoAssertion($nameId, $email, $name === null ? null : Str::limit($name, 255, ''), $mfa);
    }

    /** @param array<string, list<string>> $attributes */
    private function email(string $nameId, ?string $format, array $attributes): string
    {
        $email = filter_var($nameId, FILTER_VALIDATE_EMAIL) !== false && ($format === null || in_array($format, [Constants::NAMEID_EMAIL_ADDRESS, Constants::NAMEID_UNSPECIFIED], true))
            ? $nameId
            : self::first($attributes, self::EMAIL_ATTRIBUTES);

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new SsoFailure('email_missing');
        }

        return Str::lower($email);
    }

    /** @param array<string, list<string>> $attributes */
    private static function first(array $attributes, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $attributes[$name][0] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function failureFor(?Throwable $error): SsoFailure
    {
        $reason = match ($error?->getCode()) {
            ValidationError::WRONG_INRESPONSETO => 'in_response_to_mismatch',
            ValidationError::WRONG_AUDIENCE => 'audience_mismatch',
            ValidationError::WRONG_ISSUER, ValidationError::ISSUER_NOT_FOUND_IN_ASSERTION, ValidationError::ISSUER_MULTIPLE_IN_RESPONSE => 'issuer_mismatch',
            ValidationError::ASSERTION_EXPIRED, ValidationError::RESPONSE_EXPIRED, ValidationError::SESSION_EXPIRED, ValidationError::ASSERTION_TOO_EARLY => 'expired',
            ValidationError::INVALID_SIGNATURE, ValidationError::NO_SIGNATURE_FOUND, ValidationError::NO_SIGNED_ASSERTION, ValidationError::NO_SIGNED_MESSAGE,
            ValidationError::WRONG_SIGNED_ELEMENT, ValidationError::INVALID_SIGNED_ELEMENT, ValidationError::UNEXPECTED_SIGNED_ELEMENTS,
            ValidationError::WRONG_NUMBER_OF_SIGNATURES, ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_RESPONSE, ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_ASSERTION,
            ValidationError::KEY_ALGORITHM_ERROR, ValidationError::UNEXPECTED_REFERENCE => 'signature_invalid',
            default => 'saml_invalid',
        };

        // The library's message names which check failed; it is kept for the log, never shown.
        return new SsoFailure($reason, $error?->getMessage());
    }

    /**
     * Run $check with php-saml's "current URL" set to the connection's ACS URL.
     *
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    private function atAcs(SsoConnection $connection, callable $check): mixed
    {
        $url = parse_url($connection->acsUrl());
        $saved = array_intersect_key($_SERVER, array_flip(['REQUEST_URI', 'QUERY_STRING']));

        Utils::setSelfProtocol($url['scheme'] ?? 'https');
        Utils::setSelfHost($url['host'] ?? '');
        Utils::setSelfPort((string) ($url['port'] ?? (($url['scheme'] ?? 'https') === 'https' ? 443 : 80)));
        Utils::setBaseURLPath(null);
        $_SERVER['REQUEST_URI'] = $url['path'] ?? '/';
        unset($_SERVER['QUERY_STRING']);

        try {
            return $check();
        } catch (Throwable) {
            return false;
        } finally {
            Utils::setBaseURL(null);
            unset($_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']);
            $_SERVER = [...$_SERVER, ...$saved];
        }
    }
}
