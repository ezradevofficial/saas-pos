<?php

namespace Modules\Ecommerce\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;

/**
 * HOOK-01: E-commerce's public webhook events (docs/webhooks.md), one per
 * kind of OrderEvent (ECOM-37): ids and amounts only, never the customer's
 * contact details. Received with `ecommerce.order.view` at the company.
 *
 * `order.ready_for_delivery.v1` (MKT-04, for couriers): a delivery order
 * became ready and its delivery waits for a rider or courier; read in the
 * fan-out job, dropped when the order is collected at the store or its
 * delivery is already handed to someone.
 */
final class EcommerceWebhookEvents
{
    private const TYPES = [
        OrderEvent::PLACED => 'order.placed.v1',
        OrderEvent::PAID => 'order.paid.v1',
        OrderEvent::STATUS_CHANGED => 'order.status_changed.v1',
        OrderEvent::COMPLETED => 'order.completed.v1',
    ];

    public static function register(WebhookCatalogue $catalogue): void
    {
        foreach (self::TYPES as $kind => $key) {
            $catalogue->register(new WebhookEventType(
                key: $key,
                module: EcommerceServiceProvider::MODULE,
                event: OrderEvent::class,
                permission: 'ecommerce.order.view',
                capture: fn (OrderEvent $e) => $e->kind !== $kind ? null : new WebhookOccurrence(array_filter([
                    'order_id' => $e->orderId,
                    'number' => $e->number,
                    'company_id' => $e->companyId,
                    'currency' => $e->currency,
                    'total_minor' => $e->totalMinor,
                    'from_status' => $e->from,
                    'to_status' => $e->to,
                ], fn ($value) => $value !== null), $e->companyId),
                fields: $kind === OrderEvent::STATUS_CHANGED
                    ? ['order_id', 'number', 'company_id', 'currency', 'total_minor', 'from_status', 'to_status']
                    : ['order_id', 'number', 'company_id', 'currency', 'total_minor'],
            ));
        }

        $catalogue->register(new WebhookEventType(
            key: 'order.ready_for_delivery.v1',
            module: EcommerceServiceProvider::MODULE,
            event: OrderEvent::class,
            permission: 'ecommerce.order.view',
            capture: fn (OrderEvent $e) => $e->kind !== OrderEvent::STATUS_CHANGED || $e->to !== Order::READY ? null
                : new WebhookOccurrence(['order_id' => $e->orderId, 'number' => $e->number, 'company_id' => $e->companyId], $e->companyId),
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $order = Order::query()->find($occurrence->data['order_id'] ?? null);
                $delivery = $order === null || $order->fulfilment !== 'delivery' ? null : Delivery::query()->where('order_id', $order->id)->first();

                if ($delivery === null || $delivery->status !== Delivery::UNASSIGNED) {
                    return null;
                }

                return $occurrence->with([...$occurrence->data, 'location_id' => $order->location_id, 'delivery_id' => $delivery->id])
                    ->at($order->company_id, $order->branch_id, $order->location_id);
            },
            fields: ['order_id', 'number', 'company_id', 'location_id', 'delivery_id'],
        ));
    }
}
