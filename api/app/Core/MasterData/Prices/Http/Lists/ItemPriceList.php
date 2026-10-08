<?php

namespace App\Core\MasterData\Prices\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Prices\Http\ItemVisibility;
use App\Core\MasterData\Prices\Http\Resources\ItemPriceResource;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceAccess;
use App\Core\MasterData\Taxes\PriceList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The prices of one price list (MD-03 follow-up): sort keys and exportable
 * columns (EXP-01). Field rules of `item` apply (RBAC-05): the item's code
 * and name, and `prices` for the amount.
 */
class ItemPriceList extends ListDefinition
{
    public function __construct(private readonly PriceList $priceList) {}

    public function name(): string
    {
        return 'prices';
    }

    public function auditAction(): string
    {
        return 'core.item_price.export';
    }

    public function title(array $filters): string
    {
        return __('core.price.list_title', ['list' => $this->priceList->name]);
    }

    public function fieldRules(): ?string
    {
        return PriceAccess::FIELD_RULES;
    }

    /**
     * The item's code and name are hidden too from a user who can't view
     * the list's company's items (`core.item.view`): search, sort and
     * export skip them (RBAC-04).
     */
    public function hiddenFields(Request $request): array
    {
        $hidden = parent::hiddenFields($request);

        return ItemVisibility::for($request, $this->priceList->id)['company'] ? $hidden : array_values(array_unique([...$hidden, 'code', 'name']));
    }

    public function fieldSources(): array
    {
        return ItemPriceResource::SOURCES;
    }

    public function resource(Model $model): JsonResource
    {
        return ItemPriceResource::make($model);
    }

    public function exportRelations(): array
    {
        return ['item:id,code,name', 'uom:id,code'];
    }

    public function sorts(): array
    {
        return [
            'item_code' => ListSort::by(['item_code'], fn (Builder $q, string $direction) => $q->orderBy('items.code', $direction)),
            'item_name' => ListSort::by(['item_name'], fn (Builder $q, string $direction) => $q->orderBy('items.name', $direction)),
            'unit' => ListSort::by(['uom_code'], fn (Builder $q, string $direction) => $q->orderBy('uoms.code', $direction)),
            'amount' => ListSort::column('amount_minor', ['amount_minor']),
            'effective_from' => ListSort::column('effective_from'),
            'min_quantity' => ListSort::column('min_quantity'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'item_code';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('item_code', 'core.price.columns.item_code'),
            ListColumn::text('item_name', 'core.price.columns.item_name'),
            ListColumn::text('unit', 'core.price.columns.unit', 'uom_code'),
            ListColumn::make('amount', 'core.price.columns.amount', ['amount_minor', 'currency'],
                fn (array $row, ItemPrice $price, ExportValues $values) => $values->money($row)),
            ListColumn::make('effective_from', 'core.price.columns.effective_from', ['effective_from'],
                fn (array $row, ItemPrice $price, ExportValues $values) => $values->date($row['effective_from'])),
            ListColumn::make('min_quantity', 'core.price.columns.min_quantity', ['min_quantity'],
                fn (array $row, ItemPrice $price, ExportValues $values) => $values->decimal($row['min_quantity'])),
            ListColumn::make('state', 'core.price.columns.state', ['state'],
                fn (array $row) => isset($row['state']) ? __('core.price.states.'.$row['state']) : null),
            ListColumn::archiveStatus('core.price.columns.status'),
            ListColumn::make('updated_at', 'core.price.columns.updated_at', ['updated_at'],
                fn (array $row, ItemPrice $price, ExportValues $values) => $values->dateTime($row['updated_at'], $this->priceList->company_id)),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters);

        if (($filters['state'] ?? null) !== null) {
            $summary[__('core.price.columns.state')] = __('core.price.states.'.$filters['state']);
        }

        return $summary;
    }

    /**
     * The list's usable prices: items still shared or the list's company's
     * (TEN-08), units still the item's; each with its `state` on $day.
     */
    public static function query(PriceList $list, string $day): Builder
    {
        $current = 'not exists (select 1 from item_prices later where later.price_list_id = item_prices.price_list_id'
            .' and later.item_id = item_prices.item_id and later.uom_id = item_prices.uom_id and later.min_quantity = item_prices.min_quantity'
            .' and later.archived_at is null and later.effective_from <= ? and later.effective_from > item_prices.effective_from)';

        return ItemPrice::query()
            ->select('item_prices.*')
            ->selectRaw("case when item_prices.effective_from > ? then 'scheduled' when {$current} then 'current' else 'replaced' end as state", [$day, $day])
            ->join('items', 'items.id', '=', 'item_prices.item_id')
            ->join('uoms', 'uoms.id', '=', 'item_prices.uom_id')
            ->where('item_prices.price_list_id', $list->id)
            ->where(fn (Builder $q) => $q->whereNull('items.company_id')->orWhere('items.company_id', $list->company_id))
            ->where(fn (Builder $q) => $q->whereColumn('item_prices.uom_id', 'items.base_uom_id')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('item_uoms')
                    ->whereColumn('item_uoms.item_id', 'item_prices.item_id')
                    ->whereColumn('item_uoms.uom_id', 'item_prices.uom_id')));
    }

    /** `?state=`: the SQL condition per state, on $day. */
    public static function whereState(Builder $query, string $state, string $day): Builder
    {
        $later = fn ($sub) => $sub->selectRaw('1')->from('item_prices as later')
            ->whereColumn('later.price_list_id', 'item_prices.price_list_id')
            ->whereColumn('later.item_id', 'item_prices.item_id')
            ->whereColumn('later.uom_id', 'item_prices.uom_id')
            ->whereColumn('later.min_quantity', 'item_prices.min_quantity')
            ->whereNull('later.archived_at')
            ->where('later.effective_from', '<=', $day)
            ->whereColumn('later.effective_from', '>', 'item_prices.effective_from');

        return match ($state) {
            'scheduled' => $query->where('item_prices.effective_from', '>', $day),
            'current' => $query->where('item_prices.effective_from', '<=', $day)->whereNotExists($later),
            'replaced' => $query->where('item_prices.effective_from', '<=', $day)->whereExists($later),
        };
    }
}
