<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\CountResource;

/** INV-09, INV-19: stock counts, newest first. */
class CountList extends InventoryList
{
    public const RELATIONS = ['company', 'branch', 'location', 'requester'];

    public function name(): string
    {
        return 'inventory-counts';
    }

    public function auditAction(): string
    {
        return 'inventory.count.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.counts');
    }

    public function resource(Model $model): JsonResource
    {
        return CountResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'inventory.columns.number'),
            self::at('created_at'),
            ListColumn::make('kind', 'inventory.columns.count_kind', ['kind'], fn (array $row) => __('inventory.count_kinds.'.$row['kind'])),
            ListColumn::make('status', 'inventory.columns.status', ['status'], fn (array $row) => __('inventory.statuses.'.$row['status'])),
            self::named('location', 'location'),
            ListColumn::make('lines_count', 'inventory.columns.lines_count', ['lines_count'], fn (array $row) => (string) $row['lines_count']),
            self::money('gain'),
            self::money('loss'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
