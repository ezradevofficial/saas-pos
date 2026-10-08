<?php

namespace App\Core\Approvals\Console;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use App\Core\Rbac\Console\SyncPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * APR-05: queue a ProcessApprovalTimers job for every tenant with a
 * pending approval whose reminder or escalation is due. Scheduled every
 * five minutes (routes/console.php). Tenant ids are read as the schema
 * owner; everything else runs in each tenant's context.
 */
class ProcessApprovalTimersCommand extends Command
{
    protected $signature = 'approvals:process-timers {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue approval reminders, escalations and final timeouts that are due';

    public function handle(): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = DB::connection(SyncPermissions::OWNER_CONNECTION)
            ->table('approval_requests')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->where('next_reminder_at', '<=', $at)->orWhere('escalate_at', '<=', $at))
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            ProcessApprovalTimers::dispatch((string) $tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant approval timer runs queued.');

        return self::SUCCESS;
    }
}
