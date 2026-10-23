<?php

namespace Modules\Ecommerce\Listeners;

use App\Core\Fiscal\FiscalQueue;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Events\ExchangeIssued;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Events\OrderLinesReturned;
use Modules\Ecommerce\Events\OrderReturned;
use Modules\Ecommerce\Integrations\EcommerceFiscalSource;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderReturn;
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
 *
 * ECOM-42, ECOM-43: a partial return's credit note and an exchange's
 * invoice are queued too; a partial return takes back its share of the
 * order's earned points while Loyalty is active (review M6).
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

    public function shouldQueue(OrderEvent|OrderReturned|OrderLinesReturned|ExchangeIssued $event): bool
    {
        return match (true) {
            $event instanceof OrderReturned => $event->delivered,
            $event instanceof OrderEvent => $event->kind === OrderEvent::COMPLETED,
            default => true,
        };
    }

    /** Review M6: the points share of every committed return of the order up to this one. */
    private function reversePoints(OrderLinesReturned $event): void
    {
        $order = Order::query()->find($event->orderId);
        $return = OrderReturn::query()->find($event->returnId);

        if ($order === null || $return === null || $order->party_id === null) {
            return;
        }

        $earlier = OrderReturn::query()->where('order_id', $order->id)->where('seq', '<', $return->seq)->whereNotNull('committed_at')->where('approval_status', '!=', 'rejected');
        $points = (int) Payment::query()->where('order_id', $order->id)->where('kind', 'points')->where('status', 'succeeded')->sum('amount_minor');
        app(OnlineLoyalty::class)->reverseEarnShare($order->id, $return->id, (clone $earlier)->pluck('id')->all(), (string) max(1, $order->total_minor - $points),
            (string) ((int) (clone $earlier)->sum('returned_total_minor') + $return->returned_total_minor));
    }

    public function handle(OrderEvent|OrderReturned|OrderLinesReturned|ExchangeIssued $event): void
    {
        if ($event instanceof OrderLinesReturned || $event instanceof ExchangeIssued) {
            $this->tenants->run($event->tenantId, function () use ($event) {
                if ($this->modules->isActive(EcommerceServiceProvider::MODULE)) {
                    $event instanceof OrderLinesReturned
                        ? $this->fiscal->enqueue(EcommerceFiscalSource::KEY, 'refund', $event->returnId, $event->companyId)
                        : $this->fiscal->enqueue(EcommerceFiscalSource::KEY, 'sale', $event->saleId, $event->companyId);

                    if ($event instanceof OrderLinesReturned && class_exists(OnlineLoyalty::class)) {
                        $this->reversePoints($event);
                    }
                }
            });

            return;
        }

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
