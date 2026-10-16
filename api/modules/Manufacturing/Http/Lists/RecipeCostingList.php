<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Http\Resources\RecipeCostingResource;

/** MFG-06: what each BOM in force costs now (full cost), per output unit, against its price before tax. */
class RecipeCostingList extends ManufacturingList
{
    public const RELATIONS = ['company', 'item'];

    public function name(): string
    {
        return 'manufacturing-recipe-costing';
    }

    public function auditAction(): string
    {
        return 'manufacturing.recipe_costing.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.recipe_costing');
    }

    public function resource(Model $model): JsonResource
    {
        return RecipeCostingResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'item' => ListSort::by(['item'], fn ($query, string $direction) => $query->orderBy(
                Item::query()->select('name')->whereColumn('items.id', 'mfg_boms.item_id')->limit(1), $direction)),
            'kind' => ListSort::column('kind'),
        ];
    }

    public function defaultSort(): string
    {
        return 'item';
    }

    public function columns(): array
    {
        return [
            self::code('code', 'item'),
            self::named('item', 'item'),
            self::enum('kind', 'manufacturing.kinds'),
            ListColumn::make('output', 'manufacturing.columns.output', ['output_qty'], fn (array $row) => trim(($row['output_qty'] ?? '').' '.($row['output_uom']['code'] ?? ''))),
            self::money('unit_cost'),
            self::money('batch_cost'),
            self::money('materials_cost'),
            self::money('labour_cost'),
            self::money('overhead_cost'),
            self::money('by_product_credit'),
            self::money('price'),
            self::money('net_price'),
            ListColumn::make('cost_share_percent', 'manufacturing.columns.cost_share_percent', ['cost_share_percent'], fn (array $row, $model, ExportValues $values) => $values->decimal($row['cost_share_percent'] ?? null)),
            ListColumn::make('uncosted', 'manufacturing.columns.uncosted', ['uncosted'], fn (array $row) => implode(', ', array_column($row['uncosted'] ?? [], 'name'))),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
