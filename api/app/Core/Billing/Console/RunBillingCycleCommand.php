<?php

namespace App\Core\Billing\Console;

use App\Core\Billing\Jobs\RunBillingCycle;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SUB-03, SUB-05: queue the billing clock (reminders, renewals, invoices,
 * past due, read-only) for every tenant with billing work due. Tenant ids
 * come from a security-definer function on the runtime connection
 * (DueTenants, ADR 002); never the owner connection.
 */
class RunBillingCycleCommand extends Command
{
    protected $signature = 'billing:cycle {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue reminders, renewals and dunning for tenants with billing due';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withBillingDue($at);

        foreach ($tenantIds as $tenantId) {
            RunBillingCycle::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant billing runs queued.');

        return self::SUCCESS;
    }
}
