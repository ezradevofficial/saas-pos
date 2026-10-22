<?php

namespace App\Core\Api\Sandbox;

use App\Core\Api\ApiKeys;
use App\Core\Api\Models\ApiKey;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\AppSecrets;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * API-05 (review): a developer sandbox with no sign-in and no API request
 * for `api.sandbox.inactive_days` (30) is closed: its status becomes
 * `closed` (nobody signs in, keys stop) and its keys are revoked. Its
 * Owners are emailed `warn_days_before` (7) days earlier, once. Activity:
 * the latest sign-in, key use, or the sandbox's creation. Audited.
 *
 * Phase 8 review H2: a sandbox whose developer has a listed app, or an app
 * installed by any tenant, is never closed for inactivity: closing it
 * would stop that app for every customer (`marketplace_developer_active`).
 * New apps can no longer be listed from a sandbox (DeveloperApps::submit,
 * AppReview::approve); this keeps the ones listed before serving.
 */
class SandboxExpiry
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly OwnerGuard $owners,
        private readonly Auditor $auditor,
    ) {}

    /** @return string `warned`, `closed` or `active` */
    public function check(CarbonImmutable $now): string
    {
        $tenant = Tenant::query()->whereKey($this->tenants->require())->firstOrFail();

        if (! $tenant->is_sandbox || $tenant->status !== 'active' || $this->servesApps($tenant)) {
            return 'active';
        }

        $lastActive = $this->lastActivity($tenant);
        $closesAt = $lastActive->addDays((int) config('api.sandbox.inactive_days', 30));
        $warnAt = $closesAt->subDays((int) config('api.sandbox.warn_days_before', 7));

        if ($now->gte($closesAt)) {
            $this->close($tenant);

            return 'closed';
        }

        $warned = $tenant->setting('sandbox_warned_at');

        if ($now->gte($warnAt) && (! is_string($warned) || CarbonImmutable::parse($warned)->lt($lastActive))) {
            foreach (User::query()->whereKey($this->owners->ownerIds())->get() as $owner) {
                $owner->notify(new SandboxClosingSoon($tenant->name, $closesAt->toDateString()));
            }

            $tenant->settings = [...($tenant->settings ?? []), 'sandbox_warned_at' => $now->toIso8601String()];
            $tenant->save();
            $this->auditor->record('core.sandbox.closing_warned', $tenant, null, ['closes_on' => $closesAt->toDateString()]);

            return 'warned';
        }

        return 'active';
    }

    /** Whether the sandbox's developer has an app listed, or installed in any tenant. */
    private function servesApps(Tenant $tenant): bool
    {
        $apps = MarketplaceApp::query()->where('developer_tenant_id', $tenant->id);

        if ((clone $apps)->whereNotNull('listed_version_id')->exists()) {
            return true;
        }

        $runtime = DB::connection(TenantContext::CONNECTION);

        foreach ((clone $apps)->pluck('id') as $appId) {
            // Answers the app's own developer tenant (ADR 002); tenant ids only.
            if (app(AppSecrets::class)->installedTenants((string) $appId, $runtime) !== []) {
                return true;
            }
        }

        return false;
    }

    private function lastActivity(Tenant $tenant): CarbonImmutable
    {
        $times = array_filter([
            $tenant->created_at?->toImmutable(),
            ($at = User::query()->max('last_sign_in_at')) ? CarbonImmutable::parse($at) : null,
            ($at = ApiKey::query()->max('last_used_at')) ? CarbonImmutable::parse($at) : null,
            ($at = DB::connection(TenantContext::CONNECTION)->table('api_key_usage_days')->max('day')) ? CarbonImmutable::parse($at)->endOfDay() : null,
        ]);

        return array_reduce($times, fn (?CarbonImmutable $latest, CarbonImmutable $at) => $latest === null || $at->gt($latest) ? $at : $latest);
    }

    private function close(Tenant $tenant): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenant) {
            $tenant->forceFill(['status' => 'closed'])->save();
            $this->auditor->record('core.sandbox.close', $tenant, ['status' => 'active'], ['status' => 'closed', 'reason' => 'inactive']);

            $system = $this->owners->ownerIds()[0] ?? null;
            $actor = $system === null ? null : User::query()->find($system);

            foreach (ApiKey::query()->whereNull('revoked_at')->get() as $key) {
                $actor === null
                    ? $key->forceFill(['revoked_at' => CarbonImmutable::now()])->save()
                    : app(ApiKeys::class)->revoke($actor, $key);
            }
        });
    }
}
