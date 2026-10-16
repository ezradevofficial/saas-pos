<?php

namespace Modules\Inventory\Contracts;

use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Str;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Balance;

/**
 * A read contract for other modules (docs/modules/ecommerce.md ECOM-04):
 * the stock on hand of items at one location, in each item's base unit
 * (decimal strings, INV-04), read under the tenant's row-level security,
 * so E-commerce never reads `inventory_*` tables. Null while Inventory is
 * not active for the tenant (RBAC-08). Values and costs are never given.
 */
final class StockLevels
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  list<string>  $itemIds
     * @return array<string, string>|null item id => quantity on hand (items without a balance: "0")
     */
    public function onHand(string $locationId, array $itemIds): ?array
    {
        if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter($itemIds, fn ($id) => is_string($id) && Str::isUuid($id))));

        if ($ids === [] || ! Str::isUuid($locationId)) {
            return [];
        }

        $found = Balance::query()->where('location_id', $locationId)->whereIn('item_id', $ids)->pluck('qty', 'item_id')->all();

        return array_combine($ids, array_map(fn (string $id) => (string) ($found[$id] ?? '0'), $ids));
    }
}
