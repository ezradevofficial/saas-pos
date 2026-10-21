<?php

namespace App\Core\Branding\Console;

use App\Core\Branding\Jobs\VerifyTenantDomains;
use App\Core\Sso\Jobs\VerifyTenantSsoDomains;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * BR-05: queue a DNS check for every active tenant with custom domains
 * waiting for their TXT record, verified domains due for their daily
 * re-check, or failed claims to archive. Scheduled every ten minutes
 * (routes/console.php).
 * Tenant ids come from an owner-owned security-definer function on the
 * runtime connection (DueTenants, ADR 002), never the owner connection;
 * each check runs in its tenant's context.
 */
class VerifyDomainsCommand extends Command
{
    protected $signature = 'domains:verify {--at= : The time to act as (ISO 8601), now by default}';

    protected $description = 'Check, re-check and expire custom domains by their DNS TXT records';

    public function handle(DueTenants $due): int
    {
        $at = CarbonImmutable::parse($this->option('at') ?? 'now')->utc()->toIso8601String();
        $tenantIds = $due->withDomainChecksDue(CarbonImmutable::parse($at));

        foreach ($tenantIds as $tenantId) {
            VerifyTenantDomains::dispatch($tenantId, $at);
        }

        // AUTH-11, ADR 023: SSO email domains are proven and re-checked the same way.
        $ssoTenantIds = $due->withSsoDomainChecksDue(CarbonImmutable::parse($at));

        foreach ($ssoTenantIds as $tenantId) {
            VerifyTenantSsoDomains::dispatch($tenantId, $at);
        }

        $this->components->info(count($tenantIds).' tenant domain checks queued.');
        $this->components->info(count($ssoTenantIds).' SSO email domain checks queued.');

        return self::SUCCESS;
    }
}
