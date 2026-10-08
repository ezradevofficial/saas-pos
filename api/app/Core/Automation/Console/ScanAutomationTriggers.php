<?php

namespace App\Core\Automation\Console;

use App\Core\Automation\Jobs\ScanTimedTriggers;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * AUTO-01: queue a ScanTimedTriggers job for every tenant with live rules
 * of the kind: schedules every minute, dates hourly (routes/console.php);
 * and `reap` every five minutes for tenants with runs or webhook
 * deliveries a dead worker left behind (Reaper).
 * Tenant ids come from owner-owned security-definer functions on the
 * runtime connection (DueTenants, ADR 002); everything else runs in each
 * tenant's own context.
 */
class ScanAutomationTriggers extends Command
{
    protected $signature = 'automation:scan {kind : schedules, dates or reap} {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue the automation schedule or date triggers that are due';

    public function handle(DueTenants $due): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, [ScanTimedTriggers::SCHEDULES, ScanTimedTriggers::DATES, ScanTimedTriggers::REAP], true)) {
            $this->components->error('The kind must be schedules, dates or reap.');

            return self::INVALID;
        }

        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = match ($kind) {
            ScanTimedTriggers::REAP => $due->withStuckAutomation($at->subMinutes((int) config('automation.stuck_minutes', 15))),
            ScanTimedTriggers::DATES => $due->withDueAutomation('date', $at),
            default => $due->withDueAutomation('schedule', $at),
        };

        foreach ($tenantIds as $tenantId) {
            ScanTimedTriggers::dispatch($tenantId, $kind, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds)." tenant {$kind} scans queued.");

        return self::SUCCESS;
    }
}
