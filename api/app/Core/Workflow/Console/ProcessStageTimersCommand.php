<?php

namespace App\Core\Workflow\Console;

use App\Core\Tenancy\DueTenants;
use App\Core\Workflow\Jobs\ProcessStageTimers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * WF-09: queue a ProcessStageTimers job for every tenant with a workflow
 * position whose reminder, overdue notice or escalation is due. Scheduled
 * every five minutes (routes/console.php). Tenant ids come from an
 * owner-owned security-definer function on the runtime connection
 * (DueTenants, ADR 002); everything else runs in each tenant's context.
 */
class ProcessStageTimersCommand extends Command
{
    protected $signature = 'workflow:process-stage-timers {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue workflow stage reminders, overdue notices and escalations that are due';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withDueStageTimers($at);

        foreach ($tenantIds as $tenantId) {
            ProcessStageTimers::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant stage timer runs queued.');

        return self::SUCCESS;
    }
}
