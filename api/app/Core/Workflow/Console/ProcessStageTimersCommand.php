<?php

namespace App\Core\Workflow\Console;

use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Workflow\Jobs\ProcessStageTimers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WF-09: queue a ProcessStageTimers job for every tenant with a workflow
 * position whose reminder, overdue notice or escalation is due. Scheduled
 * every five minutes (routes/console.php). Tenant ids are read as the
 * schema owner; everything else runs in each tenant's context.
 */
class ProcessStageTimersCommand extends Command
{
    protected $signature = 'workflow:process-stage-timers {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue workflow stage reminders, overdue notices and escalations that are due';

    public function handle(): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = DB::connection(SyncPermissions::OWNER_CONNECTION)
            ->table('document_workflow_tokens')
            ->where('status', 'active')
            ->where('next_timer_at', '<=', $at)
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            ProcessStageTimers::dispatch((string) $tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant stage timer runs queued.');

        return self::SUCCESS;
    }
}
