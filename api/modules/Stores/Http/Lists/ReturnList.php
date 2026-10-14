<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\ReturnResource;

/** STR-06, STR-18: returns to store, newest first. */
class ReturnList extends StoresList
{
    public const RELATIONS = ['issue', 'company', 'branch', 'location', 'department', 'costCentre', 'returner'];

    public function name(): string
    {
        return 'stores-returns';
    }

    public function auditAction(): string
    {
        return 'stores.return.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.returns');
    }

    public function resource(Model $model): JsonResource
    {
        return ReturnResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'returned_at' => ListSort::column('returned_at'),
            'number' => ListSort::column('number'),
        ];
    }

    public function defaultSort(): string
    {
        return '-returned_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'stores.columns.number'),
            self::at('returned_at'),
            ListColumn::make('issue', 'stores.columns.issue', ['issue'], fn (array $row) => $row['issue']['number'] ?? null),
            ListColumn::make('reason', 'stores.columns.reason', ['reason'], fn (array $row) => __('stores.return_reasons.'.$row['reason'])),
            self::named('location', 'location'),
            self::named('department', 'department'),
            self::named('returned_by', 'returned_by'),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
