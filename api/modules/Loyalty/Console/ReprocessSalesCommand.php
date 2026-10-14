<?php

namespace Modules\Loyalty\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Pos\PosSales;

/** LOY-03: process again the POS sales Loyalty kept but could not process (`failed` or left `pending`). */
class ReprocessSalesCommand extends Command
{
    protected $signature = 'loyalty:reprocess';

    protected $description = 'Process again the POS sales loyalty kept but could not process';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, PosSales $sales): int
    {
        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($modules, $sales) {
                if (! $modules->isActive(LoyaltyServiceProvider::MODULE)) {
                    return;
                }

                LoyaltySale::query()->whereIn('status', [LoyaltySale::FAILED, LoyaltySale::PENDING])->where('updated_at', '<', now()->subMinute())
                    ->orderBy('sold_at')->pluck('id')
                    ->each(fn (string $id) => $sales->process($id));
            });
        }

        return self::SUCCESS;
    }
}
