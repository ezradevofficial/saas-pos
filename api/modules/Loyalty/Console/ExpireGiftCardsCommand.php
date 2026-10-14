<?php

namespace Modules\Loyalty\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\LoyaltyServiceProvider;

/** Stage 7B: write off what is left on expired gift cards (breakage), in every active tenant with the module. */
class ExpireGiftCardsCommand extends Command
{
    protected $signature = 'loyalty:expire-gift-cards';

    protected $description = 'Write off the balance left on gift cards past their expiry date';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, GiftCards $cards): int
    {
        $at = CarbonImmutable::now();

        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($modules, $cards, $at, $tenantId) {
                if ($modules->isActive(LoyaltyServiceProvider::MODULE)) {
                    $this->line("{$tenantId}: {$cards->expire($at)} gift cards expired");
                }
            });
        }

        return self::SUCCESS;
    }
}
