<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\MovementResource;

/** INV-04, INV-19: the stock ledger, newest first. */
class MovementList extends InventoryList
{
    public const RELATIONS = ['item', 'location', 'batch', 'uom', 'creator'];

    public function name(): string
    {
        return 'inventory-movements';
    }

    public function auditAction(): string
    {
        return 'inventory.movement.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.movements');
    }

    public function resource(Model $model): JsonResource
    {
        return MovementResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'occurred_at' => ListSort::column('occurred_at'),
            'kind' => ListSort::column('kind'),
            'qty' => ListSort::column('qty'),
        ];
    }

    public function defaultSort(): string
    {
        return '-occurred_at';
    }

    public function columns(): array
    {
        return [
            self::at('occurred_at'),
            ListColumn::make('kind', 'inventory.columns.kind', ['kind'], fn (array $row) => __('inventory.kinds.'.$row['kind'])),
            ListColumn::make('code', 'inventory.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::name('item', 'item'),
            self::name('location', 'location'),
            ListColumn::make('batch', 'inventory.columns.batch', ['batch'], fn (array $row) => $row['batch']['batch_number'] ?? null),
            self::qty('qty', 'qty'),
            self::qty('balance_after', 'balance_after'),
            self::money('value'),
            ListColumn::make('flags', 'inventory.columns.flags', ['flags'], fn (array $row, $model, ExportValues $values) => implode(', ', array_map(fn ($f) => __('inventory.flags.'.($f['code'] ?? '')), $row['flags']))),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
