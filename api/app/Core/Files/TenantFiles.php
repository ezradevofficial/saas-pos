<?php

namespace App\Core\Files;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TEN-01, NFR-07: where tenant files go and what they are called.
 *
 * Disk: every new file is written to the disk named by
 * `filesystems.tenant_files` (MEDIA_DISK): `media` (local,
 * storage/app/media) in development and tests, `s3` (Linode Object
 * Storage, one private bucket) in staging and production. A row keeps the
 * disk it was written to, and readers open that disk, so files written
 * before a switch stay readable (on the object store the `media` disk is
 * the same bucket, see config/filesystems.php).
 *
 * Keys: every object key is built here, and every tenant key starts with
 * `tenants/{tenant uuid}/`, so a bucket listing, a lifecycle rule, a
 * tenant's full export (EXP-02) or a clean-up can never mix two tenants.
 * The only other keys are the platform's own (`platform/...`: readiness
 * and preflight probes), which hold no tenant data. TenantKeysTest fails
 * when code builds a key anywhere else.
 *
 * Files are never public. Readers get a short-lived signed API route bound
 * to the user that checks the tenant and the user's permission again when
 * the file is fetched, and the API streams the file from the disk; public
 * branding and storefront images are streamed by the API with cache
 * headers. Nothing hands out a presigned object-store URL (docs/deploy-linode.md,
 * Object Storage).
 */
final class TenantFiles
{
    public const PREFIX = 'tenants/';

    public const PLATFORM_PREFIX = 'platform/';

    /** The disk new tenant files are written to. */
    public static function disk(): string
    {
        return (string) config('filesystems.tenant_files', 'media');
    }

    /** Whether $disk is an object store (the s3 driver). */
    public static function isObjectStore(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /**
     * `tenants/{tenant}/{segments...}`.
     *
     * @throws InvalidArgumentException when the tenant is not a UUID or a segment is empty, absolute or climbs (`..`)
     */
    public static function key(string $tenantId, string ...$segments): string
    {
        if (! Str::isUuid($tenantId)) {
            throw new InvalidArgumentException('A tenant file key needs the tenant\'s id.');
        }

        return self::PREFIX.strtolower($tenantId).'/'.self::join($segments);
    }

    /** `platform/{segments...}`: the platform's own objects (probes), never tenant data. */
    public static function platformKey(string ...$segments): string
    {
        return self::PLATFORM_PREFIX.self::join($segments);
    }

    /** The tenant a key belongs to, or null when it is not a well-formed tenant key. */
    public static function tenantOf(string $key): ?string
    {
        if (preg_match('#^tenants/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/(.+)\z#', $key, $parts) !== 1) {
            return null;
        }

        foreach (explode('/', $parts[2]) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $parts[1];
    }

    /** @param  list<string>  $segments */
    private static function join(array $segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            foreach (explode('/', $segment) as $part) {
                if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\') || str_contains($part, "\0")) {
                    throw new InvalidArgumentException('A file key segment must not be empty, absolute or climb.');
                }

                $parts[] = $part;
            }
        }

        if ($parts === []) {
            throw new InvalidArgumentException('A file key needs a name.');
        }

        return implode('/', $parts);
    }
}
