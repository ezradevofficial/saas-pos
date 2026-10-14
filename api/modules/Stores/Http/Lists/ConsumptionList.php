<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\ConsumptionResource;

/** STR-12: a consumption report, one row per group and item. */
class ConsumptionList extends StoresList
{
    public const RELATIONS = ['company', 'item.baseUom'];

    public function name(): string
    {
        return 'stores-consumption';
    }

    public function auditAction(): string
    {
        return 'stores.consumption.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.consumption_by.'.($filters['group'] ?? 'department'));
    }

    public function resource(Model $model): JsonResource
    {
        return ConsumptionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'group' => ListSort::by(['group'], fn ($query, string $direction) => $query->orderBy('group_key', $direction)),
            'item' => ListSort::by(['item'], fn ($query, string $direction) => $query->orderByRaw('min(items.name) '.$direction)),
            'net_qty' => ListSort::by(['net_qty'], fn ($query, string $direction) => $query->orderByRaw('(coalesce(sum(case when stores_movements.kind = \'issue\' then -stores_movements.qty end), 0) - coalesce(sum(case when stores_movements.kind = \'return\' then stores_movements.qty end), 0)) '.$direction)),
        ];
    }

    public function defaultSort(): string
    {
        return 'group';
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return [
            ...parent::filterSummary($filters, $values),
            __('stores.columns.group') => __('stores.groups.'.($filters['group'] ?? 'department')),
            ...(isset($filters['from']) ? [__('stores.columns.from') => (string) $filters['from']] : []),
            ...(isset($filters['to']) ? [__('stores.columns.to') => (string) $filters['to']] : []),
        ];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('group', 'stores.columns.group', ['group'], fn (array $row) => $row['group']['name'] ?? __('stores.groups.none')),
            ListColumn::make('code', 'stores.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::named('item', 'item'),
            ListColumn::make('unit', 'stores.columns.unit', ['unit'], fn (array $row) => $row['unit']['code'] ?? null),
            self::qty('issued_qty', 'issued_qty'),
            self::qty('returned_qty', 'returned_qty'),
            self::qty('net_qty', 'net_qty'),
            self::money('issued_value'),
            self::money('returned_value'),
            self::money('net_value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
