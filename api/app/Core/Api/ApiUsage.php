<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API-02, API-03: a lightweight request counter per key and UTC day
 * (`api_key_usage_days`, one upsert per request) and the key's last use,
 * written at most once a minute. Telemetry, not a business change: not
 * audited (the key's create, rotate and revoke are).
 */
class ApiUsage
{
    public function record(ApiKey $key, ?string $ip): void
    {
        $now = CarbonImmutable::now();
        $db = DB::connection(TenantContext::CONNECTION);

        $db->statement(
            'insert into api_key_usage_days (id, tenant_id, api_key_id, day, requests, created_at, updated_at)
             values (?, ?, ?, ?, 1, ?, ?)
             on conflict (api_key_id, day) do update set requests = api_key_usage_days.requests + 1, updated_at = excluded.updated_at',
            [(string) Str::uuid7(), $key->tenant_id, $key->id, $now->utc()->toDateString(), $now, $now],
        );

        $db->table('api_keys')->where('id', $key->id)
            ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $now->subMinute()))
            ->update(['last_used_at' => $now, 'last_used_ip' => $ip === null ? null : substr($ip, 0, 45)]);
    }

    /**
     * Requests per UTC day for the last $days days (zero-filled, oldest
     * first), for one key or every key of the tenant.
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
            ->groupBy('day')
            ->selectRaw('day::text as day, sum(requests)::bigint as requests')
            ->pluck('requests', 'day');

        $series = [];

        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $series[] = ['day' => $day->toDateString(), 'requests' => (int) ($counts[$day->toDateString()] ?? 0)];
        }

        return $series;
    }
}
