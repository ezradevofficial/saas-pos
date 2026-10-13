<?php

namespace Modules\Loyalty\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Points\PointsExpiry;

/** LOY-06: expire due points and send expiry notices, in every active tenant with the module. */
class ExpirePointsCommand extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Expire loyalty points that are due and warn about points expiring soon';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, PointsExpiry $expiry): int
    {
        $at = CarbonImmutable::now();

        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($modules, $expiry, $at, $tenantId) {
                if ($modules->isActive(LoyaltyServiceProvider::MODULE)) {
                    $points = $expiry->run($at);
                    $this->line("{$tenantId}: {$points} points expired");
                }
            });
        }

        return self::SUCCESS;
    }
}
