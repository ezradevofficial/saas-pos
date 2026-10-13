<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ValuationResource;

/** INV-20: what each company holds of each item and its value. */
class ValuationList extends InventoryList
{
    public const RELATIONS = ['company', 'item.baseUom'];

    public function name(): string
    {
        return 'inventory-valuation';
    }

    public function auditAction(): string
    {
        return 'inventory.valuation.export';
    }

    public function title(array $filters): string
    {
        return __('inventory.lists.valuation');
    }

    /** The whole report is for valuation holders: nothing is hidden in it. */
    public function hiddenFields(Request $request): array
    {
        return [];
    }

    public function resource(Model $model): JsonResource
    {
        return ValuationResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.code', $d)),
            'item' => ListSort::by(['item'], fn (Builder $q, string $d) => $q->orderBy('items.name', $d)),
            'qty' => ListSort::column('qty'),
            'value' => ListSort::column('value_minor', ['value']),
        ];
    }

    public function defaultSort(): string
    {
        return '-value';
    }

    public function columns(): array
    {
        return [
            self::name('company', 'company'),
            ListColumn::make('code', 'inventory.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::name('item', 'item'),
            self::qty('qty', 'qty'),
            ListColumn::make('unit', 'inventory.columns.unit', ['base_uom'], fn (array $row) => $row['base_uom']['code'] ?? null),
            ListColumn::make('unit_cost', 'inventory.columns.unit_cost', ['unit_cost_minor'], fn (array $row, $model, ExportValues $values) => $row['unit_cost_minor'] === null ? null
                : $row['currency'].' '.$values->decimal((string) BigDecimal::of($row['unit_cost_minor'])->withPointMovedLeft(app(CurrencyDecimals::class)->for($row['currency'])))),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
