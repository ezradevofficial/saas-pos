<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\TenderResource;

/** PRC-06, PRC-21: the tenders list and its export, newest first. */
class TenderList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'creator'];

    public function name(): string
    {
        return 'procurement-tenders';
    }

    public function auditAction(): string
    {
        return 'procurement.tender.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.tenders');
    }

    public function resource(Model $model): JsonResource
    {
        return TenderResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            ListColumn::text('title', 'procurement.columns.title'),
            ListColumn::make('kind', 'procurement.columns.kind', ['kind'], fn (array $row) => __('procurement.tender_kinds.'.$row['kind'])),
            self::named('branch'),
            self::at('deadline_at'),
            self::at('opening_at'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
