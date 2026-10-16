<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Http\Resources\ConsumptionResource;

/** MFG-18: actual against standard consumption, one row per order and material. */
class ConsumptionList extends ManufacturingList
{
    public const RELATIONS = ['order.company', 'order.location', 'item', 'uom'];

    public function name(): string
    {
        return 'manufacturing-consumption';
    }

    public function auditAction(): string
    {
        return 'manufacturing.consumption.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.consumption');
    }

    public function resource(Model $model): JsonResource
    {
        return ConsumptionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'order' => ListSort::by(['order'], fn ($query, string $direction) => $query->orderBy('order_id', $direction)->orderBy('line_no')),
            'line_no' => ListSort::column('line_no'),
        ];
    }

    public function defaultSort(): string
    {
        return 'order';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('order', 'manufacturing.columns.order', ['order'], fn (array $row) => $row['order']['number'] ?? null),
            self::code('code', 'item'),
            self::named('item', 'item'),
            self::code('unit', 'uom'),
            self::qty('required'),
            self::qty('standard'),
            self::qty('expected_loss'),
            self::qty('actual'),
            self::qty('variance_qty'),
            ListColumn::make('variance_percent', 'manufacturing.columns.variance_percent', ['variance_percent'], fn (array $row, $model, ExportValues $values) => $values->decimal($row['variance_percent'] ?? null)),
            self::money('actual_value'),
            self::money('standard_value'),
            self::money('value_variance'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
