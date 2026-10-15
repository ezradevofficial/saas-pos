<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Http\Resources\YieldResource;

/** MFG-19: wastage, yield and the cost of each released or closed order. */
class YieldList extends ManufacturingList
{
    public const RELATIONS = ['company', 'location', 'item', 'uom', 'outputs.item'];

    public function name(): string
    {
        return 'manufacturing-yield';
    }

    public function auditAction(): string
    {
        return 'manufacturing.yield.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.yield');
    }

    public function resource(Model $model): JsonResource
    {
        return YieldResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'released_at' => ListSort::column('released_at'),
            'number' => ListSort::column('number'),
        ];
    }

    public function defaultSort(): string
    {
        return '-released_at';
    }

    public function columns(): array
    {
        $percent = fn (string $key) => ListColumn::make($key, 'manufacturing.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->decimal($row[$key] ?? null));

        return [
            ListColumn::text('number', 'manufacturing.columns.number'),
            self::enum('status', 'manufacturing.statuses'),
            self::code('code', 'item'),
            self::named('item', 'item'),
            self::code('unit', 'uom'),
            self::qty('planned'),
            self::qty('good'),
            self::qty('scrap'),
            $percent('yield_percent'),
            $percent('scrap_percent'),
            self::money('material_cost'),
            self::money('labour_cost'),
            self::money('overhead_cost'),
            self::money('relieved'),
            self::money('variance'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
