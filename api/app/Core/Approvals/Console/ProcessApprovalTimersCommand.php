<?php

namespace App\Core\Approvals\Console;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * APR-05: queue a ProcessApprovalTimers job for every tenant with a
 * pending approval whose reminder or escalation is due. Scheduled every
 * five minutes (routes/console.php). Tenant ids come from an owner-owned
 * security-definer function on the runtime connection (DueTenants,
 * ADR 002); everything else runs in each tenant's context.
 */
class ProcessApprovalTimersCommand extends Command
{
    protected $signature = 'approvals:process-timers {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue approval reminders, escalations and final timeouts that are due';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withDueApprovalTimers($at);

        foreach ($tenantIds as $tenantId) {
            ProcessApprovalTimers::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant approval timer runs queued.');

        return self::SUCCESS;
    }
}
