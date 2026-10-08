<?php

namespace App\Core\Payments\Console;

use App\Core\Payments\Jobs\ProcessPaymentTimers;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Queue a ProcessPaymentTimers job for every tenant with a payment intent
 * past its timeout or a manual code due for a check. Scheduled every
 * minute (routes/console.php). Tenant ids come from an owner-owned
 * security-definer function on the runtime connection (DueTenants, ADR
 * 002): the scheduler never needs the owner's credentials.
 */
class ProcessPaymentTimersCommand extends Command
{
    protected $signature = 'payments:process-timers {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue payment timeouts and checks of manual payment codes that are due';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withDuePaymentIntents($at);

        foreach ($tenantIds as $tenantId) {
            ProcessPaymentTimers::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' tenant payment timer runs queued.');

        return self::SUCCESS;
    }
}
