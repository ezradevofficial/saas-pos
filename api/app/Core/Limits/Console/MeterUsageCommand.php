<?php

namespace App\Core\Limits\Console;

use App\Core\Limits\Jobs\MeterTenantUsage;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * LIM-01: the daily count of every active tenant's outlets, devices, users
 * and employees, and the overage warnings, reminders and closing that
 * follow (LIM-02, LIM-03). Scheduled daily (routes/console.php). Tenant
 * ids come from the owner-owned security-definer function
 * `app_active_tenant_ids()` on the runtime connection (DueTenants, ADR 002),
 * never the owner connection; each tenant is metered in its own context.
 */
class MeterUsageCommand extends Command
{
    protected $signature = 'usage:meter {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Count each tenant\'s outlets, devices, users and employees against its plan';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $tenantIds = $due->active();

        foreach ($tenantIds as $tenantId) {
            MeterTenantUsage::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant usage counts queued.');

        return self::SUCCESS;
    }
}
