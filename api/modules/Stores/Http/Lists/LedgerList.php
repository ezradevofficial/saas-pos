<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\MovementResource;

/** STR-10: the store ledger, newest first (in the order the movements happened). */
class LedgerList extends StoresList
{
    public const RELATIONS = ['company', 'location', 'item.baseUom', 'uom', 'department', 'costCentre', 'creator'];

    public function name(): string
    {
        return 'stores-ledger';
    }

    public function auditAction(): string
    {
        return 'stores.ledger.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.ledger');
    }

    public function resource(Model $model): JsonResource
    {
        return MovementResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'occurred_at' => ListSort::by(['occurred_at'], fn ($query, string $direction) => $query->orderBy('stores_movements.seq', $direction)),
            'kind' => ListSort::column('kind'),
        ];
    }

    public function defaultSort(): string
    {
        return '-occurred_at';
    }

    public function columns(): array
    {
        return [
            self::at('occurred_at'),
            ListColumn::make('kind', 'stores.columns.kind', ['kind'], fn (array $row) => __('stores.kinds.'.$row['kind'])),
            ListColumn::make('reference', 'stores.columns.reference', ['source'], fn (array $row) => $row['source']['reference'] ?? null),
            self::named('location', 'location'),
            ListColumn::make('code', 'stores.columns.code', ['item'], fn (array $row) => $row['item']['code'] ?? null),
            self::named('item', 'item'),
            self::qty('qty', 'qty'),
            ListColumn::make('unit', 'stores.columns.unit', ['unit'], fn (array $row) => $row['unit']['code'] ?? null),
            self::qty('balance_after', 'balance_after'),
            self::named('department', 'department'),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
