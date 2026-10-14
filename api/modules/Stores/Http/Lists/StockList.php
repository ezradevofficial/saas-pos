<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\StockResource;

/** STR-11: the own ledger's stock on hand per store and item, by item name. */
class StockList extends StoresList
{
    public const RELATIONS = ['company', 'location', 'item.baseUom'];

    public function name(): string
    {
        return 'stores-stock';
    }

    public function auditAction(): string
    {
        return 'stores.stock.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.stock');
    }

    public function resource(Model $model): JsonResource
    {
        return StockResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'item' => ListSort::by(['item'], fn ($query, string $direction) => $query->orderBy('items.name', $direction)),
            'qty' => ListSort::by(['qty'], fn ($query, string $direction) => $query->orderBy('stores_movements.balance_after', $direction)),
        ];
    }

    public function defaultSort(): string
    {
        return 'item';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('code', 'stores.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::named('item', 'item'),
            self::named('location', 'location'),
            self::qty('qty', 'qty'),
            ListColumn::make('unit', 'stores.columns.unit', ['unit'], fn (array $row) => $row['unit']['code'] ?? null),
            self::at('last_moved_at'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
