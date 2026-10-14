<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\OrderResource;

/** PRC-13, PRC-21: the orders list and its export, newest first. */
class OrderList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'location', 'party', 'requester'];

    public function name(): string
    {
        return 'procurement-orders';
    }

    public function auditAction(): string
    {
        return 'procurement.po.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.orders');
    }

    public function resource(Model $model): JsonResource
    {
        return OrderResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            self::named('party'),
            self::named('branch'),
            ListColumn::make('order_date', 'procurement.columns.order_date', ['order_date'], fn (array $row, $model, ExportValues $values) => $values->date($row['order_date'] ?? null)),
            ListColumn::make('expected_on', 'procurement.columns.expected_on', ['expected_on'], fn (array $row, $model, ExportValues $values) => $values->date($row['expected_on'] ?? null)),
            self::money('total'),
            self::money('base_total'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
