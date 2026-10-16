<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Http\Resources\OrderResource;

/** MFG-10 to MFG-14, MFG-27: production orders, newest first. */
class OrderList extends ManufacturingList
{
    public const RELATIONS = ['company', 'branch', 'location', 'item', 'uom', 'planner', 'releaser', 'closer'];

    public function name(): string
    {
        return 'manufacturing-orders';
    }

    public function auditAction(): string
    {
        return 'manufacturing.order.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.orders');
    }

    public function resource(Model $model): JsonResource
    {
        return OrderResource::make($model);
    }

    public function customFieldEntity(): ?string
    {
        return OrderEntity::KEY;
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
            'planned_start' => ListSort::column('planned_start'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'manufacturing.columns.number'),
            self::enum('status', 'manufacturing.statuses'),
            self::code('code', 'item'),
            self::named('item', 'item'),
            self::qty('qty'),
            self::code('unit', 'uom'),
            self::qty('qty_good'),
            self::qty('qty_scrap'),
            ListColumn::make('planned_start', 'manufacturing.columns.planned_start', ['planned_start'], fn (array $row) => $row['planned_start'] ?? null),
            ListColumn::make('planned_end', 'manufacturing.columns.planned_end', ['planned_end'], fn (array $row) => $row['planned_end'] ?? null),
            self::named('location', 'location'),
            self::named('planned_by', 'planned_by'),
            self::money('material_cost'),
            self::money('relieved'),
            self::money('variance'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
