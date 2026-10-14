<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\IssueResource;

/** STR-04, STR-05, STR-18: issues, newest first. */
class IssueList extends StoresList
{
    public const RELATIONS = ['requisition', 'company', 'branch', 'location', 'department', 'costCentre', 'issuer', 'acknowledger'];

    public function name(): string
    {
        return 'stores-issues';
    }

    public function auditAction(): string
    {
        return 'stores.issue.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.issues');
    }

    public function resource(Model $model): JsonResource
    {
        return IssueResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'issued_at' => ListSort::column('issued_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-issued_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'stores.columns.number'),
            self::at('issued_at'),
            self::status(),
            ListColumn::make('requisition', 'stores.columns.requisition', ['requisition'], fn (array $row) => $row['requisition']['number'] ?? null),
            self::named('location', 'location'),
            self::named('department', 'department'),
            self::named('cost_centre', 'cost_centre'),
            self::named('issued_by', 'issued_by'),
            self::named('acknowledged_by', 'acknowledged_by'),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
