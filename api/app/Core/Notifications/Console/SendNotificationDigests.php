<?php

namespace App\Core\Notifications\Console;

use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * NOT-05: queue a SendDigests job for every tenant with emails held for a
 * digest. Scheduled hourly (routes/console.php): each user's digest goes
 * out at the first run after the digest hour in their time zone. Tenant
 * ids come from an owner-owned security-definer function on the runtime
 * connection (DueTenants, ADR 002); everything else in each tenant's
 * context.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue the daily and weekly notification digests that are due';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $tenantIds = $due->withPendingDigests();

        foreach ($tenantIds as $tenantId) {
            SendDigests::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant digest runs queued.');

        return self::SUCCESS;
    }
}
