<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\RfqResource;

/** PRC-03, PRC-21: the rfqs list and its export, newest first. */
class RfqList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'requisition', 'creator'];

    public function name(): string
    {
        return 'procurement-rfqs';
    }

    public function auditAction(): string
    {
        return 'procurement.rfq.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.rfqs');
    }

    public function resource(Model $model): JsonResource
    {
        return RfqResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            ListColumn::text('title', 'procurement.columns.title'),
            self::named('branch'),
            self::at('deadline_at'),
            ListColumn::make('quotes_count', 'procurement.columns.quotes_count', ['quotes_count'], fn (array $row, $model, ExportValues $values) => $values->integer($row['quotes_count'] ?? null)),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
