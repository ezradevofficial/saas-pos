<?php

namespace App\Core\Branding\Console;

use App\Core\Branding\Jobs\VerifyTenantDomains;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * BR-05: queue a DNS check for every tenant with custom domains waiting
 * for their TXT record. Scheduled every ten minutes (routes/console.php).
 * Tenant ids come from an owner-owned security-definer function on the
 * runtime connection (DueTenants, ADR 002), never the owner connection;
 * each check runs in its tenant's context.
 */
class VerifyDomainsCommand extends Command
{
    protected $signature = 'domains:verify {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Check the DNS TXT records of custom domains waiting for verification';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $tenantIds = $due->withPendingDomains();

        foreach ($tenantIds as $tenantId) {
            VerifyTenantDomains::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant domain checks queued.');

        return self::SUCCESS;
    }
}
