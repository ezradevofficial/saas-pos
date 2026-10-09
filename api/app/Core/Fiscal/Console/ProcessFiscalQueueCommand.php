<?php

namespace App\Core\Fiscal\Console;

use App\Core\Fiscal\Jobs\ProcessFiscalQueue;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Queue a ProcessFiscalQueue job for every tenant with a fiscal submission
 * due (or left `sending` by a dead worker). Scheduled every minute
 * (routes/console.php). Tenant ids come from an owner-owned
 * security-definer function on the runtime connection (DueTenants, ADR
 * 002): the scheduler never needs the owner's credentials.
 */
class ProcessFiscalQueueCommand extends Command
{
    protected $signature = 'fiscal:process {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue the fiscal submissions that are due for the tax authority';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withDueFiscalSubmissions($at, $at->subMinutes((int) config('fiscal.stuck_minutes', 10)));

        foreach ($tenantIds as $tenantId) {
            ProcessFiscalQueue::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant fiscal queue runs queued.');

        return self::SUCCESS;
    }
}
