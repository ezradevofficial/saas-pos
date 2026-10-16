<?php

namespace App\Core\Api;

use Illuminate\Support\Str;

/**
 * API-02: the plain key a client sends, `sk_<mode>_<id>_<secret>`. `live`
 * keys belong to live tenants and `test` keys to sandbox tenants (API-05);
 * the id is the key's `public_id` and the secret is stored only as a hash.
 */
final class ApiKeyToken
{
    public const PREFIX = 'sk_';

    public const MODE_LIVE = 'live';

    public const MODE_TEST = 'test';

    private const PATTERN = '/^sk_(live|test)_([a-z0-9]{16})_([A-Za-z0-9]{40})$/D';

    private function __construct(
        public readonly string $mode,
        public readonly string $publicId,
        public readonly string $secret,
    ) {}

    /** A bearer token that claims to be a key (routed to the key guard, never to Sanctum's tokens). */
    public static function claims(?string $bearer): bool
    {
        return is_string($bearer) && str_starts_with($bearer, self::PREFIX);
    }

    public static function parse(string $plain): ?self
    {
        if (preg_match(self::PATTERN, $plain, $m) !== 1) {
            return null;
        }

        return new self($m[1], $m[2], $m[3]);
    }

    /** A new key for a tenant in $mode, with a fresh id and secret. */
    public static function generate(string $mode, ?string $publicId = null): self
    {
        return new self($mode, $publicId ?? Str::lower(Str::random(16)), Str::random(40));
    }

    public function plain(): string
    {
        return self::PREFIX."{$this->mode}_{$this->publicId}_{$this->secret}";
    }

    public function hash(): string
    {
        return self::hashSecret($this->secret);
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function isSandbox(): bool
    {
        return $this->mode === self::MODE_TEST;
    }

    /** What the back office shows of a key: its prefix and id, never the secret. */
    public static function display(string $mode, string $publicId): string
    {
        return self::PREFIX."{$mode}_{$publicId}_…";
    }
}
