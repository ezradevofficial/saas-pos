<?php

namespace Modules\Ecommerce\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Events\OrderEvent;

/**
 * HOOK-01: E-commerce's public webhook events (docs/webhooks.md), one per
 * kind of OrderEvent (ECOM-37): ids and amounts only, never the customer's
 * contact details. Received with `ecommerce.order.view` at the company.
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
    }
}
