<?php

namespace App\Core\Rbac;

use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NFR-02, RBAC-08, RBAC-12: the tenant's permissions version.
 *
 * What a request needs to know about a user's access (their role
 * assignments, the tenant's active modules, the resulting permission list)
 * is kept in the cache for a few minutes, under a key made of the tenant,
 * the user and this version. The version changes on any role, role
 * permission, assignment, module or subscription change of the tenant
 * (those models call bump(), and so does the permission cache flush,
 * RBAC-12), so the next request reads fresh data straight away and an old
 * entry is simply never read again.
 *
 * The version is per tenant: a cache entry made for tenant A has a key that
 * carries A's id and A's version, and is never found under B's. Nothing is
 * cached or read from the cache inside an application transaction (its
 * uncommitted changes must never reach the shared cache, and it must see
 * its own changes); a bump is repeated after commit, as the permission
 * cache flush is (TenantPermissionRegistrar).
 */
class AccessVersion
{
    /** Seconds an entry lives at most, whatever the version (defence in depth). */
    public const TTL = 300;

    /** @var array<string, string> tenant id => version, for this request or job */
    private array $memo = [];

    public function __construct(private readonly Repository $cache, private readonly TenantContext $tenants) {}

    /** The current tenant's version, or null without a tenant. */
    public function current(): ?string
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return null;
        }

        return $this->memo[$tenantId] ??= $this->read($tenantId);
    }

    /**
     * The cache key for $part of the current tenant's access data (a user's
     * assignments, the tenant's modules...), or null when nothing may be
     * cached now: no tenant, or inside a transaction.
     */
    public function key(string $part, ?string $userId = null): ?string
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null || self::inTransaction()) {
            return null;
        }

        return implode(':', array_filter(['access', $tenantId, $userId, $this->current(), $part], fn ($p) => $p !== null));
    }

    /** Remember $compute() under $part for the current tenant (and $userId), until the version changes. */
    public function remember(string $part, ?string $userId, callable $compute): mixed
    {
        $key = $this->key($part, $userId);

        if ($key === null) {
            return $compute();
        }

        $found = $this->cache->get($key);

        if ($found !== null) {
            return $found;
        }

        $value = $compute();

        // A change that landed while computing moved the version on: do not store under the old one.
        if ($this->key($part, $userId) === $key) {
            $this->cache->put($key, $value, self::TTL);
        }

        return $value;
    }

    /** Something that decides what users may do changed for the current tenant (or $tenantId). */
    public function bump(?string $tenantId = null): void
    {
        $tenantId ??= $this->tenants->id();

        if ($tenantId === null) {
            return;
        }

        $this->change($tenantId);

        if (self::inTransaction()) {
            DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => $this->change($tenantId));
        }
    }

    /** Forget the per-request copy (a new request or job starts). */
    public function flush(): void
    {
        $this->memo = [];
    }

    private function change(string $tenantId): void
    {
        $version = Str::random(12);
        $this->cache->forever(self::versionKey($tenantId), $version);
        $this->memo[$tenantId] = $version;
    }

    private function read(string $tenantId): string
    {
        $key = self::versionKey($tenantId);
        $version = $this->cache->get($key);

        if (is_string($version)) {
            return $version;
        }

        // First use, or the cache lost it: a new version, so nothing stale can match.
        $this->cache->add($key, Str::random(12), 86400 * 30);

        return (string) $this->cache->get($key, Str::random(12));
    }

    private static function versionKey(string $tenantId): string
    {
        return 'access:version:'.$tenantId;
    }

    /** Inside an application transaction on the tenant connection (a database test's wrapping one does not count). */
    private static function inTransaction(): bool
    {
        return app('db.transactions')->callbackApplicableTransactions()
            ->contains(fn ($transaction) => $transaction->connection === TenantContext::CONNECTION);
    }
}
