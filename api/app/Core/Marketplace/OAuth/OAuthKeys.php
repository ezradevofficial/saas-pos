<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Http\ApiException;
use Laravel\Passport\Passport;

/**
 * API-02 (OAuth): the RSA key pair that signs access tokens. Set in the
 * environment (PASSPORT_PRIVATE_KEY, PASSPORT_PUBLIC_KEY) or as files from
 * `php artisan passport:keys`; an owner input on every server. Without it
 * the marketplace's OAuth endpoints answer 503 `oauth_not_configured` and
 * no app token is accepted. The test suite uses a key pair made for the
 * process (MarketplaceServiceProvider), never a committed one.
 */
final class OAuthKeys
{
    public static function configured(): bool
    {
        foreach (['private', 'public'] as $type) {
            $inline = (string) config("passport.{$type}_key");

            if ($inline === '' && ! is_readable(Passport::keyPath("oauth-{$type}.key"))) {
                return false;
            }
        }

        return true;
    }

    public static function assertConfigured(): void
    {
        if (! self::configured()) {
            throw new ApiException(503, 'oauth_not_configured', __('marketplace.errors.oauth_not_configured'));
        }
    }

    /** @return array{private: string, public: string} a fresh 2048-bit pair (tests and local set-up only) */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }
}
