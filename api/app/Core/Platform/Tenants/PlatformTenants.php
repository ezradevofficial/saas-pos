<?php

namespace App\Core\Platform\Tenants;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Subscriptions\BillingTrial;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Limits\Models\UsageMeter;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADM-01: tenant management for the console.
 *
 * - Search runs over the operator's accounts (a table of the operator
 *   tenant). Owner email goes through the sign-in lookup
 *   (`auth_tenant_for_login`, ADR 002), then is confirmed in that tenant's
 *   context to be an Owner. No other tenant table is read.
 * - A tenant's usage and health are read in its own context, as counts
 *   and times only, never records.
 * - Suspend, reactivate and extend a trial carry a reason. Each is audited
 *   in the tenant's chain (as the system, naming the staff member) and in
 *   the platform chain, and notifies the tenant's Owners.
 */
class PlatformTenants
{
    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
        private readonly Accounts $accounts,
        private readonly Auditor $auditor,
        private readonly AuditContext $audit,
        private readonly PlatformAuditor $platformAudit,
        private readonly PlatformNotices $notices,
        private readonly TenantHealthProbes $probes,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  array{search?: ?string, owner_email?: ?string, country?: ?string, plan?: ?string, status?: ?string, module?: ?string}  $filters
     * @return Collection<int, OperatorAccount>
     */
    public function search(array $filters): Collection
    {
        if ($this->operator->id() === null) {
            return collect();
        }

        $ownerTenant = null;

        if (filled($filters['owner_email'] ?? null)) {
            $ownerTenant = $this->tenantOwnedBy((string) $filters['owner_email']);

            if ($ownerTenant === null) {
                return collect();
            }
        }

        return $this->operator->run(fn () => OperatorAccount::query()
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.addcslashes(mb_strtolower(trim((string) $filters['search'])), '%_\\').'%';
                $q->where(fn (Builder $q) => $q->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(slug) like ?', [$term]));
            })
            ->when($ownerTenant !== null, fn (Builder $q) => $q->where('customer_tenant_id', $ownerTenant))
            ->when(filled($filters['country'] ?? null), fn (Builder $q) => $q->where('country', strtoupper((string) $filters['country'])))
            ->when(filled($filters['plan'] ?? null), fn (Builder $q) => $q->where('bundle', (string) $filters['plan']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when(filled($filters['module'] ?? null), fn (Builder $q) => $q->whereJsonContains('modules', (string) $filters['module']))
            ->orderBy('name')
            ->limit(500)
            ->get());
    }

    /** The tenant whose Owner signs in with $email, or null. */
    private function tenantOwnedBy(string $email): ?string
    {
        $tenantId = DB::connection(TenantContext::CONNECTION)->selectOne('select public.auth_tenant_for_login(?) as id', [mb_strtolower(trim($email))])?->id;

        if ($tenantId === null) {
            return null;
        }

        return $this->tenants->run((string) $tenantId, function () use ($email) {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

            return $user !== null && app(OwnerGuard::class)->isOwner($user) ? $this->tenants->id() : null;
        });
    }

    /**
     * Usage, modules and health of $tenant, read in its own context.
     *
     * @return array<string, mixed>
     */
    public function overview(string $tenant): array
    {
        return $this->tenants->run($tenant, function () use ($tenant) {
            $day = UsageMeter::query()->max('day');
            $usage = $day === null ? collect() : UsageMeter::query()->where('day', $day)->orderBy('unit')->get();
            $devices = Device::query()->where('status', Device::STATUS_ACTIVE)->get(['id', 'last_seen_at']);
            $oldest = $devices->min(fn (Device $d) => $d->last_seen_at?->getTimestamp() ?? 0);
            $active = $this->modules->active();
            $probes = [];

            foreach ($this->probes->all() as $key => $probe) {
                $probes[$key] = in_array($probe['module'], $active, true) ? ($probe['count'])() : null;
            }

            $lastActivity = DB::connection(TenantContext::CONNECTION)->table('audit_logs')->max('occurred_at');

            return [
                'status' => Tenant::query()->whereKey($tenant)->value('status'),
                'modules' => array_values(array_diff($active, [ModuleRegistry::CORE])),
                'usage' => [
                    'day' => $day === null ? null : CarbonImmutable::parse($day)->toDateString(),
                    'units' => $usage->map(fn (UsageMeter $m) => ['unit' => $m->unit, 'count' => $m->count, 'allowed' => $m->allowed])->values()->all(),
                ],
                'health' => [
                    'last_activity_at' => $lastActivity === null ? null : CarbonImmutable::parse($lastActivity)->toIso8601String(),
                    'devices' => $devices->count(),
                    'devices_never_seen' => $devices->whereNull('last_seen_at')->count(),
                    'oldest_device_contact_at' => $devices->isEmpty() || $oldest === 0 ? null : CarbonImmutable::createFromTimestamp($oldest)->toIso8601String(),
                    'sync_lag_seconds' => $devices->isEmpty() || $oldest === 0 ? null : max(0, CarbonImmutable::now()->getTimestamp() - $oldest),
                    'failed_jobs' => $this->failedJobs($tenant),
                    ...$probes,
                ],
            ];
        });
    }

    /** Failed queue jobs that carry the tenant's id (the queue tables are global; a count only). */
    private function failedJobs(string $tenant): int
    {
        return (int) DB::connection(TenantContext::CONNECTION)->table('failed_jobs')->where('payload', 'like', '%'.$tenant.'%')->count();
    }

    /** ADM-01c: suspend: writes refused, tills stopped, the scheduler skips the tenant. */
    public function suspend(string $tenant, string $reason, PlatformUser $staff): void
    {
        $this->change($tenant, $staff, 'platform.tenant.suspend', $reason, function (?Subscription $subscription, Tenant $model) {
            if ($model->status === 'suspended') {
                throw new ApiException(422, 'tenant_already_suspended', __('platform.tenants.already_suspended'));
            }

            $before = ['status' => $model->status, 'subscription_status' => $subscription?->status];
            $model->forceFill(['status' => 'suspended'])->save();

            if ($subscription !== null && $subscription->status !== Subscription::SUSPENDED) {
                $subscription->fill(['status_before_suspension' => $subscription->status, 'status' => Subscription::SUSPENDED])->save();
            }

            return [$before, ['status' => 'suspended', 'subscription_status' => $subscription?->status]];
        }, PlatformNotices::SUSPENDED);
    }

    /** ADM-01c: reactivate: the subscription's status from before the suspension comes back. */
    public function reactivate(string $tenant, string $reason, PlatformUser $staff): void
    {
        $this->change($tenant, $staff, 'platform.tenant.reactivate', $reason, function (?Subscription $subscription, Tenant $model) {
            if ($model->status !== 'suspended') {
                throw new ApiException(422, 'tenant_not_suspended', __('platform.tenants.not_suspended'));
            }

            $before = ['status' => $model->status, 'subscription_status' => $subscription?->status];
            $model->forceFill(['status' => 'active'])->save();

            if ($subscription !== null && $subscription->status === Subscription::SUSPENDED) {
                $subscription->fill(['status' => $subscription->status_before_suspension ?? Subscription::ACTIVE, 'status_before_suspension' => null])->save();
            }

            return [$before, ['status' => 'active', 'subscription_status' => $subscription?->status]];
        }, PlatformNotices::REACTIVATED);
    }

    /** ONB-04, ADM-01c: move a trial's end later. */
    public function extendTrial(string $tenant, CarbonImmutable $until, string $reason, PlatformUser $staff): void
    {
        $this->change($tenant, $staff, 'platform.subscription.trial_extend', $reason, function (?Subscription $subscription) use ($until) {
            if ($subscription === null || $subscription->status !== Subscription::TRIAL || $subscription->hasPeriod()) {
                throw new ApiException(422, 'not_in_trial', __('billing.errors.not_in_trial'));
            }

            $before = ['trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()];
            $subscription->fill(['trial_ends_at' => $until])->save();
            // One trial end: onboarding's record follows the subscription's.
            BillingTrial::syncOnboarding($subscription);

            return [$before, ['trial_ends_at' => $until->toIso8601String()]];
        }, PlatformNotices::TRIAL_EXTENDED, ['date' => $until->toDateString()]);
    }

    /**
     * One staff action in the tenant's context: the change, under the
     * subscription's and tenant's row locks (a racing billing cycle or
     * "Subscribe now" waits), audited in the tenant's chain as the system
     * naming the staff member; then the operator's account follows, the
     * platform chain records it and the Owners are told.
     *
     * @param  callable(?Subscription, Tenant): array{0: array<string, mixed>, 1: array<string, mixed>}  $apply
     * @param  array<string, string>  $data
     */
    private function change(string $tenant, PlatformUser $staff, string $action, string $reason, callable $apply, string $notice, array $data = []): void
    {
        $this->account404($tenant);

        [$before, $after] = $this->tenants->run($tenant, function () use ($tenant, $staff, $action, $reason, $apply, $notice, $data) {
            $result = $this->audit->actingAs(null, ['platform_user_id' => $staff->id, 'staff_name' => $staff->name], fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenant, $action, $reason, $apply) {
                $model = Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
                $subscription = Subscription::query()->lockForUpdate()->first();
                [$before, $after] = $apply($subscription, $model);
                $this->auditor->record($action, $subscription ?? $model, $before, [...$after, 'reason' => $reason]);

                return [$before, $after, $subscription];
            }));

            if ($result[2] !== null) {
                $this->accounts->sync($result[2]->fresh());
            }

            rescue(fn () => $this->notices->toOwners($notice, ['reason' => $reason, ...$data]), report: true);

            return [$result[0], $result[1]];
        });

        $this->platformAudit->record($action, null, ['tenant_id' => $tenant, ...$before], ['tenant_id' => $tenant, ...$after, 'reason' => $reason]);
    }

    public function account404(string $tenant): OperatorAccount
    {
        $account = $this->operator->id() === null ? null : $this->operator->run(fn () => OperatorAccount::query()->where('customer_tenant_id', $tenant)->first());

        return $account ?? abort(404);
    }
}
