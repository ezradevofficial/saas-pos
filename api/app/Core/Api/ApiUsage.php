<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API-02, API-03: requests per key and UTC day, counted in the cache
 * (Redis in production: one INCR per admitted request, nothing written to
 * the database per request) and flushed to `api_key_usage_days` by the
 * scheduled `api:flush-usage`, which sets each day's total (idempotent).
 * The key's last use is kept the same way. A tenant's daily total feeds a
 * sandbox's daily cap (API-05). Telemetry, not audited.
 */
class ApiUsage
{
    /** Days a counter is kept in the cache: today and the two before, so a late flush still finds them. */
    public const CACHE_DAYS = 3;

    public function record(ApiKey $key, ?string $ip): void
    {
        $now = CarbonImmutable::now();
        $day = $now->utc()->toDateString();

        $this->increment(self::keyCounter($key->id, $day));
        $this->increment(self::tenantCounter($key->tenant_id, $day));
        Cache::put(self::lastUsed($key->id), ['at' => $now->toIso8601String(), 'ip' => $ip === null ? null : substr($ip, 0, 45)], $now->addDays(self::CACHE_DAYS));
    }

    /** Requests the tenant's keys made today (UTC), counted so far. */
    public function tenantToday(string $tenantId): int
    {
        return (int) Cache::get(self::tenantCounter($tenantId, CarbonImmutable::now()->utc()->toDateString()), 0);
    }

    /**
     * Write the cached counters and last uses of the current tenant's keys
     * to the database: each day's row is set to the larger of what it holds
     * and the counter (a second flush changes nothing).
     */
    public function flush(): int
    {
        $db = DB::connection(TenantContext::CONNECTION);
        $now = CarbonImmutable::now();
        $written = 0;

        foreach (ApiKey::query()->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', $now->subDays(self::CACHE_DAYS)))->get(['id', 'tenant_id']) as $key) {
            foreach ($this->cachedDays() as $day) {
                $count = (int) Cache::get(self::keyCounter($key->id, $day), 0);

                if ($count === 0) {
                    continue;
                }

                $db->statement(
                    'insert into api_key_usage_days (id, tenant_id, api_key_id, day, requests, created_at, updated_at)
                     values (?, ?, ?, ?, ?, ?, ?)
                     on conflict (api_key_id, day) do update set requests = greatest(api_key_usage_days.requests, excluded.requests), updated_at = excluded.updated_at',
                    [(string) Str::uuid7(), $key->tenant_id, $key->id, $day, $count, $now, $now],
                );
                $written++;
            }

            $last = Cache::get(self::lastUsed($key->id));

            if (is_array($last) && isset($last['at'])) {
                $db->table('api_keys')->where('id', $key->id)
                    ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $last['at']))
                    ->update(['last_used_at' => $last['at'], 'last_used_ip' => $last['ip'] ?? null]);
            }
        }

        return $written;
    }

    /**
     * Requests per UTC day for the last $days days (zero-filled, oldest
     * first), for one key or every key of the tenant: the flushed rows,
     * with the counters not flushed yet.
     *
     * @return list<array{day: string, requests: int}>
     */
    public function perDay(int $days, ?string $keyId = null): array
    {
        $today = CarbonImmutable::now()->utc()->startOfDay();
        $from = $today->subDays($days - 1);

        $counts = DB::connection(TenantContext::CONNECTION)->table('api_key_usage_days')
            ->where('day', '>=', $from->toDateString())
            ->when($keyId !== null, fn ($q) => $q->where('api_key_id', $keyId))
            ->groupBy('api_key_id', 'day')
            ->selectRaw('api_key_id, day::text as day, sum(requests)::bigint as requests')
            ->get()
            ->groupBy('day')
            ->map(fn ($rows) => $rows->pluck('requests', 'api_key_id')->map(fn ($v) => (int) $v)->all())
            ->all();

        $keyIds = $keyId !== null ? [$keyId] : ApiKey::query()->pluck('id')->all();

        foreach ($this->cachedDays() as $day) {
            foreach ($keyIds as $id) {
                $cached = (int) Cache::get(self::keyCounter($id, $day), 0);
                $counts[$day][$id] = max($counts[$day][$id] ?? 0, $cached);
            }
        }

        $series = [];

        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $series[] = ['day' => $day->toDateString(), 'requests' => array_sum($counts[$day->toDateString()] ?? [])];
        }

        return $series;
    }

    /** The key's last use: the cached one when newer than the stored one (not flushed yet). */
    public function lastUsedAt(ApiKey $key): ?CarbonImmutable
    {
        $cached = Cache::get(self::lastUsed($key->id));
        $at = is_array($cached) && isset($cached['at']) ? CarbonImmutable::parse($cached['at']) : null;

        return match (true) {
            $at === null => $key->last_used_at,
            $key->last_used_at === null => $at,
            default => $at->max($key->last_used_at),
        };
    }

    /** @return list<string> the UTC days whose counters may still be in the cache */
    private function cachedDays(): array
    {
        $today = CarbonImmutable::now()->utc()->startOfDay();

        return array_map(fn (int $back) => $today->subDays($back)->toDateString(), range(self::CACHE_DAYS - 1, 0));
    }

    private function increment(string $counter): void
    {
        Cache::add($counter, 0, CarbonImmutable::now()->addDays(self::CACHE_DAYS));
        Cache::increment($counter);
    }

    public static function keyCounter(string $keyId, string $day): string
    {
        return "api-usage|key|{$keyId}|{$day}";
    }

    public static function tenantCounter(string $tenantId, string $day): string
    {
        return "api-usage|tenant|{$tenantId}|{$day}";
    }

    private static function lastUsed(string $keyId): string
    {
        return "api-usage|last|{$keyId}";
    }
}
