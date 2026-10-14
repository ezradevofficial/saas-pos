<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Http\Resources\RequisitionResource;

/** STR-02, STR-18: requisitions, newest first. */
class RequisitionList extends StoresList
{
    public const RELATIONS = ['company', 'branch', 'location', 'department', 'costCentre', 'requester'];

    public function name(): string
    {
        return 'stores-requisitions';
    }

    public function auditAction(): string
    {
        return 'stores.requisition.export';
    }

    public function title(array $filters): string
    {
        return __('stores.lists.requisitions');
    }

    public function resource(Model $model): JsonResource
    {
        return RequisitionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
            'needed_by' => ListSort::column('needed_by'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'stores.columns.number'),
            self::at('created_at'),
            self::status(),
            self::named('location', 'location'),
            self::named('department', 'department'),
            self::named('cost_centre', 'cost_centre'),
            ListColumn::text('needed_by', 'stores.columns.needed_by'),
            self::named('requested_by', 'requested_by'),
            ListColumn::text('lines_count', 'stores.columns.lines_count'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
