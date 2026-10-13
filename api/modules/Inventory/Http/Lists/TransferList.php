<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\TransferResource;

/** INV-08, INV-19: transfers, newest first. */
class TransferList extends InventoryList
{
    public const RELATIONS = ['company', 'branch', 'fromLocation', 'toLocation', 'requester'];

    public function name(): string
    {
        return 'inventory-transfers';
    }

    public function auditAction(): string
    {
        return 'inventory.transfer.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.transfers');
    }

    public function resource(Model $model): JsonResource
    {
        return TransferResource::make($model);
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
            ListColumn::make('status', 'inventory.columns.status', ['status'], fn (array $row) => __('inventory.statuses.'.$row['status'])),
            self::name('from_location', 'from_location'),
            self::name('to_location', 'to_location'),
            ListColumn::make('lines_count', 'inventory.columns.lines_count', ['lines_count'], fn (array $row) => (string) $row['lines_count']),
            self::name('requested_by', 'requested_by'),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
