<?php

namespace Modules\Inventory\Listeners;

use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\MasterData\Items\Item;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\InventoryServiceProvider;

/**
 * INV-11, NOT-02: stock fell to its reorder level; the holders of
 * `inventory.reorder.edit` where it happened (the location, its branch,
 * company or the whole tenant) are told once, through the core
 * notification service (in-app and email by default).
 */
class NotifyLowStock
{
    public const EVENT = 'inventory.stock.low';

    public const PERMISSION = 'inventory.reorder.edit';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApproverDirectory $directory,
        private readonly ScopeResolver $scopes,
        private readonly ModuleRegistry $modules,
    ) {}

    public function handle(StockLow $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            $chain = $this->scopes->chainOf(Scope::location($event->locationId)) ?? [];
            $users = $this->directory->holdersAt($this->directory->rolesWith(self::PERMISSION), $chain);

            if ($users === []) {
                return;
            }

            $item = Item::query()->with('baseUom')->find($event->itemId);

            app(Notifier::class)->send(new NotificationEvent(self::EVENT, $users, [
                'item_name' => (string) $item?->name,
                'item_code' => (string) $item?->code,
                'location_name' => (string) Location::query()->whereKey($event->locationId)->value('name'),
                'quantity' => $event->qty,
                // INV-11: quantities are in the item's base unit, named in the message.
                'unit' => (string) $item?->baseUom?->code,
                'reorder_level' => $event->reorderLevel,
                'reorder_quantity' => $event->reorderQty,
            ], '/inventory/items/'.rawurlencode($event->itemId)));
        });
    }
}
