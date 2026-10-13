<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\StockResource;

/** INV-05, INV-19: stock on hand, one row per item and location. */
class StockList extends InventoryList
{
    public const RELATIONS = ['item.baseUom', 'location'];

    public function name(): string
    {
        return 'inventory-stock';
    }

    public function auditAction(): string
    {
        return 'inventory.stock.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.stock');
    }

    public function resource(Model $model): JsonResource
    {
        return StockResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'item' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.name', $d)),
            'code' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.code', $d)),
            'qty' => ListSort::column('qty'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('code', 'inventory.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::name('item', 'item'),
            self::name('location', 'location'),
            self::qty('qty', 'qty'),
            ListColumn::make('unit', 'inventory.columns.unit', ['base_uom'], fn (array $row) => $row['base_uom']['code'] ?? null),
            self::qty('reorder_level', 'reorder_level'),
            ListColumn::make('low', 'inventory.columns.low', ['low'], fn (array $row) => $row['low'] ? __('inventory.columns.low_yes') : ''),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
