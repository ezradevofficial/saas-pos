<?php

namespace App\Core\Webhooks\Console;

use App\Core\Tenancy\DueTenants;
use App\Core\Webhooks\Jobs\SweepWebhooks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * HOOK-01: queue the delivery log housekeeping (SweepWebhooks) of every
 * tenant with deliveries past their retention or left behind by a dead
 * worker. Scheduled every five minutes (routes/console.php). Tenant ids
 * come from a security-definer function on the runtime connection
 * (DueTenants, ADR 002), never the owner connection.
 */
class SweepWebhooksCommand extends Command
{
    protected $signature = 'webhooks:sweep {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Prune old webhook deliveries and requeue ones a dead worker left behind';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc();
        $tenantIds = $due->withWebhookWork(
            $at->subMinutes((int) config('webhooks.stuck_minutes', 15)),
            $at->subDays((int) config('webhooks.retention_days', 30)),
        );

        foreach ($tenantIds as $tenantId) {
            SweepWebhooks::dispatch($tenantId, $at->toIso8601String());
        }

        $this->components->info(count($tenantIds).' webhook sweeps queued.');

        return self::SUCCESS;
    }
}
