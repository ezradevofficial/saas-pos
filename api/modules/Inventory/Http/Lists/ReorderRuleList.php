<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ReorderRuleResource;

/** INV-11, INV-19: reorder rules, low ones first. */
class ReorderRuleList extends InventoryList
{
    public const RELATIONS = ['item.baseUom', 'location'];

    public function name(): string
    {
        return 'inventory-reorder-rules';
    }

    public function auditAction(): string
    {
        return 'inventory.reorder_rule.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.reorder_rules');
    }

    public function resource(Model $model): JsonResource
    {
        return ReorderRuleResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.code', $d)),
            'item' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.name', $d)),
            'low_since' => ListSort::column('low_since'),
            'reorder_level' => ListSort::column('reorder_level'),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('code', 'inventory.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::name('item', 'item'),
            self::name('location', 'location'),
            self::qty('reorder_level', 'reorder_level'),
            self::qty('reorder_qty', 'reorder_qty'),
            self::qty('qty', 'on_hand'),
            ListColumn::make('low', 'inventory.columns.low', ['low'], fn (array $row) => $row['low'] ? __('inventory.columns.low_yes') : ''),
            ListColumn::make('low_since', 'inventory.columns.low_since', ['low_since'], fn (array $row, $model, ExportValues $values) => $values->dateTime($row['low_since'], $row['company_id'])),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
