<?php

namespace App\Core\Notifications\Console;

use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Rbac\Console\SyncPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NOT-05: queue a SendDigests job for every tenant with emails held for a
 * digest. Scheduled hourly (routes/console.php): each user's digest goes
 * out at the first run after the digest hour in their time zone. Tenant
 * ids are read as the owner; everything else in each tenant's context.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Queue the daily and weekly notification digests that are due';

    public function handle(): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $tenantIds = DB::connection(SyncPermissions::OWNER_CONNECTION)
            ->table('notification_deliveries')
            ->where('status', 'pending_digest')
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            SendDigests::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant digest runs queued.');

        return self::SUCCESS;
    }
}
