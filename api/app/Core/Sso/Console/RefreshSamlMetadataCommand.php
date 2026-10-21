<?php

namespace App\Core\Sso\Console;

use App\Core\Sso\Jobs\RefreshTenantSamlMetadata;
use App\Core\Tenancy\DueTenants;
use Illuminate\Console\Command;

/**
 * AUTH-11, ADR 023: queue a re-read of SAML metadata fetched by URL for
 * every active tenant that has some. Scheduled daily on one server
 * (routes/console.php). Tenant ids come from a security-definer function
 * on the runtime connection (DueTenants, ADR 002), never the owner
 * connection; each refresh runs in its tenant's context.
 */
class RefreshSamlMetadataCommand extends Command
{
    protected $signature = 'sso:refresh-metadata';

    protected $description = 'Re-read SAML metadata fetched by URL and keep rotated signing certificates';

    public function handle(DueTenants $due): int
    {
        $tenantIds = $due->withSsoMetadataUrls();

        foreach ($tenantIds as $tenantId) {
            RefreshTenantSamlMetadata::dispatch($tenantId);
        }

        $this->components->info(count($tenantIds).' SAML metadata refreshes queued.');

        return self::SUCCESS;
    }
}
