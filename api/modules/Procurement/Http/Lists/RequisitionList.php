<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\RequisitionResource;

/** PRC-02, PRC-21: the requisitions list and its export, newest first. */
class RequisitionList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'location', 'requester', 'department'];

    public function name(): string
    {
        return 'procurement-requisitions';
    }

    public function auditAction(): string
    {
        return 'procurement.requisition.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.requisitions');
    }

    public function resource(Model $model): JsonResource
    {
        return RequisitionResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            self::named('branch'),
            self::named('requested_by'),
            ListColumn::text('purpose', 'procurement.columns.purpose'),
            self::money('estimated_total'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
