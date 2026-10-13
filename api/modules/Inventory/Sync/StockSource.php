<?php

namespace Modules\Inventory\Sync;

use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Sources\Iso;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Stock\Qty;

/**
 * INV-17, NFR-04: stock on hand of each item at the till's location
 * (`inventory_stock`, incremental, read-only): `{id, item_id, qty,
 * updated_at}`, the quantity in the item's base unit as a decimal string.
 * Every posting re-stamps the balance rows it moves (`sync_stamp`), so a
 * till sees stock change after each sale's upload is posted. Balances
 * never leave their location nor are deleted, so no tombstone trigger is
 * needed; the till only shows the figure and never refuses a sale on it.
 */
class StockSource implements IncrementalSource
{
    public const KEY = 'inventory_stock';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return InventoryServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'inventory_balances';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where('location_id', $scope->location->id);
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('inventory_balances'), $scope)
            ->whereIn('id', $ids)
            ->get(['id', 'item_id', 'qty', 'updated_at'])
            ->mapWithKeys(fn (object $row) => [$row->id => [
                'id' => $row->id,
                'item_id' => $row->item_id,
                'qty' => Qty::show((string) $row->qty),
                'updated_at' => Iso::of($row->updated_at),
            ]])
            ->all();
    }
}
