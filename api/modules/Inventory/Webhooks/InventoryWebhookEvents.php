<?php

namespace Modules\Inventory\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\InventoryServiceProvider;

/**
 * HOOK-01: Inventory's public webhook events (docs/webhooks.md). Quantities
 * are decimal strings in the item's base unit. Received with
 * `inventory.stock.view` at the location.
 */
final class InventoryWebhookEvents
{
    public static function register(WebhookCatalogue $catalogue): void
    {
        $catalogue->register(new WebhookEventType(
            key: 'stock.low.v1',
            module: InventoryServiceProvider::MODULE,
            event: StockLow::class,
            permission: 'inventory.stock.view',
            capture: fn (StockLow $e) => new WebhookOccurrence([
                'item_id' => $e->itemId,
                'company_id' => $e->companyId,
                'location_id' => $e->locationId,
                'qty' => $e->qty,
                'reorder_level' => $e->reorderLevel,
                'reorder_qty' => $e->reorderQty,
            ], $e->companyId, null, $e->locationId),
            fields: ['item_id', 'company_id', 'location_id', 'qty', 'reorder_level', 'reorder_qty'],
        ));
    }
}
