<?php

namespace App\Core\Api\Sandbox;

use App\Core\Api\ApiUsage;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * API-05 (review): a developer sandbox is free, so it has hard caps
 * (`api.sandbox.caps`): users, devices and outlets (checked where usage
 * limits are, UsageLimits::ensureCanAdd), records created since it was
 * set up or reset (every audited `*.create`, checked before each write
 * request), and API requests a day. Over a cap: 403
 * `sandbox_limit_reached`, or 429 `sandbox_daily_limit` for requests.
 */
class SandboxCaps
{
    public const UNITS = ['user', 'device', 'outlet'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApiUsage $usage,
    ) {}

    /** Refuse adding one more $unit when $count already reaches the cap. */
    public function assertCanAdd(string $unit, int $count): void
    {
        $cap = config("api.sandbox.caps.{$unit}");

        if (in_array($unit, self::UNITS, true) && $cap !== null && $count >= (int) $cap) {
            throw $this->reached($unit, (int) $cap);
        }
    }

    /** Refuse a write once the records created since set-up (or the last reset) reach the cap. */
    public function assertCanCreateRecord(): void
    {
        $cap = (int) config('api.sandbox.caps.records', 500);

        if ($this->recordsCreated() >= $cap) {
            throw $this->reached('records', $cap);
        }
    }

    public function recordsCreated(): int
    {
        $from = Tenant::query()->whereKey($this->tenants->require())->first()?->setting('sandbox_counted_from');

        return DB::connection(TenantContext::CONNECTION)->table('audit_logs')
            ->where('action', 'like', '%.create')
            ->when(is_string($from), fn ($q) => $q->where('occurred_at', '>', $from))
            ->count();
    }

    /** Records count from now (set-up, reset). */
    public function restartRecordCount(): void
    {
        $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();
        $tenant->settings = [...($tenant->settings ?? []), 'sandbox_counted_from' => CarbonImmutable::now()->format('Y-m-d\\TH:i:s.uP')];
        $tenant->save();
    }

    /** API-03: refuse a key's request once the sandbox used its requests of the day. */
    public function assertApiRequestAllowed(string $tenantId): void
    {
        $cap = (int) config('api.sandbox.caps.api_requests_per_day', 1000);

        if ($this->usage->tenantToday($tenantId) >= $cap) {
            $retry = max(1, (int) CarbonImmutable::now()->utc()->endOfDay()->diffInSeconds(CarbonImmutable::now()->utc()));

            throw new ApiException(429, 'sandbox_daily_limit', __('api.errors.sandbox_daily_limit', ['max' => $cap]), headers: ['Retry-After' => $retry]);
        }
    }

    private function reached(string $what, int $cap): ApiException
    {
        return new ApiException(403, 'sandbox_limit_reached', __('api.errors.sandbox_limit_reached', [
            'what' => __("api.sandbox.caps.{$what}"),
            'max' => $cap,
        ]), extra: ['limit' => $what, 'max' => $cap]);
    }
}
