<?php

namespace App\Core\Privacy;

use Illuminate\Support\Str;

/**
 * NFR-08: the token in a marketing email's unsubscribe link. It names the
 * tenant and the delivery, and is signed with an HMAC of the application
 * key under its own purpose label, so it is good for this one thing only
 * (it opens nothing else, and no other signed value is accepted here). The
 * tenant id is trusted only because the signature covers it; the delivery
 * is then looked up under that tenant's row-level security. Previous keys
 * (APP_PREVIOUS_KEYS) still verify, so links in emails sent before an
 * APP_KEY rotation keep working.
 *
 *     <tenant id>.<delivery id>.<base64url signature>
 */
final class OptOutTokens
{
    private const PURPOSE = 'marketing-opt-out:v1';

    public function make(string $tenantId, string $deliveryId): string
    {
        return "{$tenantId}.{$deliveryId}.".$this->sign($tenantId, $deliveryId, $this->keys()[0]);
    }

    /** @return array{tenant: string, delivery: string}|null the ids, or null for a malformed, tampered or foreign token */
    public function read(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || ! Str::isUuid($parts[0]) || ! Str::isUuid($parts[1]) || preg_match('/^[A-Za-z0-9_-]{43}$/', $parts[2]) !== 1) {
            return null;
        }

        foreach ($this->keys() as $key) {
            if (hash_equals($this->sign($parts[0], $parts[1], $key), $parts[2])) {
                return ['tenant' => strtolower($parts[0]), 'delivery' => strtolower($parts[1])];
            }
        }

        return null;
    }

    private function sign(string $tenantId, string $deliveryId, string $key): string
    {
        $mac = hash_hmac('sha256', self::PURPOSE.'|'.strtolower($tenantId).'|'.strtolower($deliveryId), $key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** @return non-empty-list<string> the current key first, then APP_PREVIOUS_KEYS */
    private function keys(): array
    {
        $keys = [];
        foreach ([(string) config('app.key'), ...array_map('strval', (array) config('app.previous_keys'))] as $key) {
            if ($key !== '') {
                $keys[] = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
            }
        }

        return $keys === [] ? throw new \RuntimeException('APP_KEY is not set.') : $keys;
    }
}
