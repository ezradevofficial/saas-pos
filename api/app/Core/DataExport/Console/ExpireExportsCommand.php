<?php

namespace App\Core\DataExport\Console;

use App\Core\DataExport\Jobs\ExpireTenantExports;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * EXP-02: queue the clean-up of every tenant with an export past its
 * expiry (the file is deleted after `data_export.keep_days`) or a build a
 * dead worker left behind. Scheduled hourly (routes/console.php). Tenant
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
        $tenantIds = $due->withDueExports(CarbonImmutable::parse($at));

        foreach ($tenantIds as $tenantId) {
            ExpireTenantExports::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant export clean-ups queued.');

        return self::SUCCESS;
    }
}
