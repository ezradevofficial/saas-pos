<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\AdjustmentResource;

/** INV-10, INV-19: adjustments, newest first. */
class AdjustmentList extends InventoryList
{
    public const RELATIONS = ['company', 'branch', 'location', 'requester'];

    public function name(): string
    {
        return 'inventory-adjustments';
    }

    public function auditAction(): string
    {
        return 'inventory.adjustment.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.adjustments');
    }

    public function resource(Model $model): JsonResource
    {
        return AdjustmentResource::make($model);
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
            ListColumn::make('reason', 'inventory.columns.reason', ['reason'], fn (array $row) => __('inventory.reasons.'.$row['reason'])),
            self::named('location', 'location'),
            self::named('requested_by', 'requested_by'),
            self::money('value_in'),
            self::money('value_out'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
