<?php

namespace Modules\Ecommerce\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Orders\OrderFlow;

/**
 * ECOM-14: unpaid online orders whose reservation time has passed are
 * cancelled (`payment_timeout`) and their stock released. The tenants come
 * from a security-definer function (ADR 002), each handled in its own
 * context under row-level security. A payment that still arrives later is
 * marked for refund (OrderPayments).
 */
class ExpireCommand extends Command
{
    protected $signature = 'ecommerce:expire';

    protected $description = 'Cancel unpaid online orders whose stock reservation expired';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, OrderFlow $flow): int
    {
        $at = CarbonImmutable::now();
        $count = 0;

        foreach ($tenants->withExpiredEcommerceReservations($at) as $tenantId) {
            $context->run($tenantId, function () use ($modules, $flow, $at, &$count) {
                if (! $modules->isActive(EcommerceServiceProvider::MODULE)) {
                    return;
                }

                $orders = Order::query()->where('status', Order::PLACED)->whereIn('payment_status', ['pending', 'failed'])
                    ->whereNotNull('reserved_until')->where('reserved_until', '<=', $at)->orderBy('reserved_until')->limit(500)->get();

                foreach ($orders as $order) {
                    rescue(function () use ($flow, $order, &$count) {
                        $flow->cancel($order, null, __('ecommerce.orders.payment_timeout'));
                        $count++;
                    }, report: true);
                }
            });
        }

        $this->info("Cancelled {$count} unpaid orders.");

        return self::SUCCESS;
    }
}
