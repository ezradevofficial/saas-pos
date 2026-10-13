<?php

namespace App\Core\Onboarding\Console;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ONB-01: self sign-ups whose owner never verified within
 * onboarding.pending_hours (24) are closed, never deleted (TEN-06): the
 * tenant's status becomes `closed` and the unverified login is released
 * (its email or phone replaced, the user deactivated), so the address
 * can sign up again. Such a tenant has no business records or subdomain
 * yet. Audited as `core.onboarding.prune` in the tenant's chain.
 */
class PrunePendingSignUpsCommand extends Command
{
    protected $signature = 'onboarding:prune-pending';

    protected $description = 'Close sign-ups whose owner never verified (ONB-01)';

    public function handle(DueTenants $due, TenantContext $tenants, Auditor $auditor, AuditContext $audit): int
    {
        $before = now()->subHours((int) config('onboarding.pending_hours', 24));
        $count = 0;

        foreach ($due->pendingVerification($before) as $tenantId) {
            $audit->reset();

            try {
                $tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenantId, $auditor) {
                    $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                    if ($tenant->status !== 'active' || User::query()->where('status', '<>', User::STATUS_PENDING)->exists()) {
                        return;
                    }

                    foreach (User::query()->where('status', User::STATUS_PENDING)->get() as $user) {
                        $user->forceFill([
                            'email' => "pruned-{$user->id}@pruned.invalid",
                            'phone' => null,
                            'status' => User::STATUS_DEACTIVATED,
                        ])->saveQuietly();
                    }

                    $tenant->forceFill(['status' => 'closed', 'slug' => null])->save();
                    $auditor->record('core.onboarding.prune', $tenant, ['status' => 'active'], ['status' => 'closed', 'reason' => 'unverified']);
                }));
                $count++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->components->info("Unverified sign-ups closed: {$count}.");

        return self::SUCCESS;
    }
}
