<?php

namespace Modules\Pharmacy\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Pharmacy\Alerts\ExpiryAlerts;
use Modules\Pharmacy\PharmacyServiceProvider;

/** PHA-10: the nightly expiry sweep (alerts and quarantine of expired stock), in every active tenant with the module. */
class ExpiryAlertsCommand extends Command
{
    protected $signature = 'pharmacy:expiry-alerts';

    protected $description = 'Quarantine expired stock and tell pharmacists about stock near expiry or expired';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, ExpiryAlerts $alerts): int
    {
        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($modules, $alerts, $tenantId) {
                if ($modules->isActive(PharmacyServiceProvider::MODULE)) {
                    $found = $alerts->run();
                    $this->line("{$tenantId}: {$found['near']} near expiry, {$found['expired']} expired, {$found['quarantined']} quarantined");
                }
            });
        }

        return self::SUCCESS;
    }
}
