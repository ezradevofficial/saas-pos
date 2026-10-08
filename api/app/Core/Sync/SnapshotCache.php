<?php

namespace App\Core\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NFR-05: snapshot entities rebuilt at most every `sync.snapshot_ttl_seconds`
 * per device (0: every pull), so 50,000 tills polling do not rebuild every
 * set each time. Keys carry the tenant, the device, the source and its
 * version, and a per-tenant epoch: changes that must reach tills at once
 * (a PIN set, reset, cleared or locked; a device secret issued, activated
 * or retired) bump the epoch after commit. Anything else may be up to the
 * TTL old on a till, which is acceptable for prices lists, payment
 * methods, rates and settings (the server re-checks at upload).
 */
class SnapshotCache
{
    /**
     * @param  Closure(): list<array<string, mixed>>  $build
     * @return list<array<string, mixed>>
     */
    public function rows(SnapshotSource $source, DeviceScope $scope, Closure $build): array
    {
        $ttl = (int) config('sync.snapshot_ttl_seconds', 30);

        if ($ttl <= 0) {
            return $build();
        }

        $key = sprintf('sync-snapshot:%s:%s:%s:%d:%s', $scope->tenantId, $scope->device->id, $source->key(), $source->version(), self::epoch($scope->tenantId));

        return Cache::remember($key, $ttl, $build);
    }

    /** Invalidate every cached snapshot of the tenant once the current transaction commits. */
    public static function bump(string $tenantId): void
    {
        DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => Cache::forever("sync-epoch:{$tenantId}", (string) Str::uuid7()));
    }

    private static function epoch(string $tenantId): string
    {
        return (string) Cache::get("sync-epoch:{$tenantId}", '0');
    }
}
