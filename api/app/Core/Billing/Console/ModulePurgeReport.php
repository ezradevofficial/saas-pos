<?php

namespace App\Core\Billing\Console;

use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SUB-07: switched-off modules keep their data for 12 months
 * (`billing.purge_after_months`). This flags the ones past that
 * (`tenant_modules.purge_flagged_at`, in each tenant's context) and puts
 * them on the operator's account of the tenant, a report for platform
 * staff. Nothing is deleted: a purge is a separate, deliberate decision.
 * Tenant ids come from a security-definer function (ADR 002).
 */
class ModulePurgeReport extends Command
{
    protected $signature = 'billing:purge-report {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Flag switched-off module data kept past its retention for platform staff (deletes nothing)';

    public function handle(DueTenants $due, TenantContext $tenants, Accounts $accounts): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $before = $at->subMonthsNoOverflow((int) config('billing.purge_after_months', 12));
        $flagged = 0;

        foreach ($due->withModulesToPurge($before) as $tenantId) {
            $tenants->run($tenantId, function () use ($before, $at, $accounts, &$flagged) {
                TenantModule::query()->where('status', TenantModule::INACTIVE)->whereNull('purge_flagged_at')
                    ->whereNotNull('switched_off_at')->where('switched_off_at', '<=', $before)->get()
                    ->each(function (TenantModule $module) use ($at, &$flagged) {
                        $module->fill(['purge_flagged_at' => $at])->save();
                        $flagged++;
                    });

                $subscription = Subscription::query()->first();

                if ($subscription !== null) {
                    $accounts->sync($subscription);
                }
            });
        }

        $this->components->info("{$flagged} switched-off modules flagged for purge (nothing deleted).");

        return self::SUCCESS;
    }
}
