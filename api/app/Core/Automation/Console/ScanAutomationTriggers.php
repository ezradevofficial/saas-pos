<?php

namespace App\Core\Automation\Console;

use App\Core\Automation\Jobs\ScanTimedTriggers;
use App\Core\Rbac\Console\SyncPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AUTO-01: queue a ScanTimedTriggers job for every tenant with live rules
 * of the kind: schedules every minute, dates hourly (routes/console.php).
 * Tenant ids are read as the schema owner; everything else runs in each
 * tenant's own context.
 */
class ScanAutomationTriggers extends Command
{
    protected $signature = 'automation:scan {kind : schedules or dates} {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue the automation schedule or date triggers that are due';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, [ScanTimedTriggers::SCHEDULES, ScanTimedTriggers::DATES], true)) {
            $this->components->error('The kind must be schedules or dates.');

            return self::INVALID;
        }

        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $query = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('automation_rules')
            ->where('enabled', true)->whereNull('archived_at')
            ->where('trigger_type', $kind === ScanTimedTriggers::DATES ? 'date' : 'schedule');

        if ($kind === ScanTimedTriggers::SCHEDULES) {
            $query->where('next_run_at', '<=', $at);
        }

        $tenantIds = $query->distinct()->orderBy('tenant_id')->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            ScanTimedTriggers::dispatch($tenantId, $kind, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds)." tenant {$kind} scans queued.");

        return self::SUCCESS;
    }
}
