<?php

namespace App\Core\Api\Console;

use App\Core\Api\ApiUsage;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * API-03 (ADR 020): writes the cached request counters and last uses of
 * every tenant's keys to `api_key_usage_days` and `api_keys`, each tenant
 * in its own context (RLS); tenant ids come from a security-definer
 * function (ADR 002). Idempotent: run every five minutes.
 */
class FlushApiUsageCommand extends Command
{
    protected $signature = 'api:flush-usage';

    protected $description = 'Write the API keys\' cached request counters to the daily usage table (API-03).';

    public function handle(DueTenants $due, TenantContext $tenants, ApiUsage $usage): int
    {
        $written = 0;

        foreach ($due->withApiKeys() as $tenantId) {
            $written += $tenants->run($tenantId, fn () => $usage->flush());
        }

        $this->info("Flushed {$written} key-day counters.");

        return self::SUCCESS;
    }
}
