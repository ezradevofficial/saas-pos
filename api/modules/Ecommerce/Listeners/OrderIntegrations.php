<?php

namespace Modules\Ecommerce\Listeners;

use App\Core\Fiscal\FiscalQueue;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Events\OrderReturned;
use Modules\Ecommerce\Integrations\EcommerceFiscalSource;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Payment;
use Modules\Loyalty\Contracts\OnlineLoyalty;

/**
 * ECOM-33, ECOM-35: after an order is delivered, it is queued for the
 * fiscal authority (when the company submits) and earns Loyalty points
 * (while Loyalty is active, for a customer with a party, on what was not
 * paid by points); after a delivered order is returned, its credit note is
 * queued and the points come off. Queued after commit, in the order's
 * tenant; each step is idempotent (the fiscal queue by document, the
 * points ledger by source).
 */
class OrderIntegrations implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly FiscalQueue $fiscal,
    ) {}

    public function shouldQueue(OrderEvent|OrderReturned $event): bool
    {
        return $event instanceof OrderReturned ? $event->delivered : $event->kind === OrderEvent::COMPLETED;
    }

    public function handle(OrderEvent|OrderReturned $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(EcommerceServiceProvider::MODULE)) {
                return;
            }

            $order = Order::query()->find($event->orderId);

            if ($order === null) {
                return;
            }

            $loyalty = class_exists(OnlineLoyalty::class) ? app(OnlineLoyalty::class) : null;

            if ($event instanceof OrderReturned) {
                $this->fiscal->enqueue(EcommerceFiscalSource::KEY, 'refund', $order->id, $order->company_id);
                $loyalty?->reverseEarn($order->id);

                return;
            }

            $this->fiscal->enqueue(EcommerceFiscalSource::KEY, 'sale', $order->id, $order->company_id);

            if ($loyalty !== null && $order->party_id !== null) {
                $points = (int) Payment::query()->where('order_id', $order->id)->where('kind', 'points')->where('status', 'succeeded')->sum('amount_minor');
                $loyalty->earn($order->company_id, $order->party_id, $order->currency, (string) max(0, $order->total_minor - $points), $order->id, $order->delivered_at ?? now()->toImmutable());
            }
        });
    }
}
