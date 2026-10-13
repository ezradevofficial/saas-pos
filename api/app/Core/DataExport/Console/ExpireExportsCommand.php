<?php

namespace App\Core\DataExport\Console;

use App\Core\DataExport\Jobs\ExpireTenantExports;
use App\Core\DataExport\TenantExportBuilder;
use App\Core\DataExport\TenantExports;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * EXP-02: queue the clean-up of every tenant with an export past its
 * expiry (the file is deleted after `data_export.keep_days`), a stale
 * build, or a file not yet deleted; and remove work directories older than
 * any build (sweepAfter) from this host. Scheduled hourly (routes/console.php). Tenant
 * ids come from an owner-owned security-definer function on the runtime
 * connection (DueTenants, ADR 002), never the owner connection.
 */
class ExpireExportsCommand extends Command
{
    protected $signature = 'exports:expire {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Delete expired full tenant exports and fail stale builds';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $when = CarbonImmutable::parse($at);
        $tenantIds = $due->withDueExports($when, TenantExports::runningStaleBefore($when), TenantExports::queuedStaleBefore($when));
        // Work directories a killed worker left on this host (plaintext rows and files).
        TenantExportBuilder::sweepTemporary(self::sweepAfter());

        foreach ($tenantIds as $tenantId) {
            ExpireTenantExports::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant export clean-ups queued.');

        return self::SUCCESS;
    }

    /** Seconds after which a work directory is left over: longer than any build may run. */
    public static function sweepAfter(): int
    {
        return (int) config('data_export.timeout', 3600) + 600;
    }
}
