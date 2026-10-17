<?php

namespace App\Core\Sso;

use RuntimeException;

/**
 * AUTH-11: why a single sign-on did not complete. `reason` is a stable code:
 * the callback sends it to the browser (`#error=`), the web app shows its
 * translation (`auth.sso.errors.{reason}`) and the audit log keeps it.
 */
final class SsoFailure extends RuntimeException
{
    public const REASONS = [
        'state_invalid', 'idp_error', 'connection_unavailable', 'token_exchange_failed',
        'signature_invalid', 'issuer_mismatch', 'audience_mismatch', 'nonce_mismatch', 'expired',
        'token_invalid', 'email_missing', 'email_unverified', 'hosted_domain_mismatch',
        'saml_invalid', 'in_response_to_mismatch', 'replayed',
        'domain_not_verified', 'no_account', 'identity_conflict', 'deactivated', 'jit_stale',
    ];

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? $reason);
    }
}
