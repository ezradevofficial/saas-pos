<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\LandedCostResource;

/** PRC-17, PRC-21: the landed costs list and its export, newest first. */
class LandedCostList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'creator'];

    public function name(): string
    {
        return 'procurement-landed-costs';
    }

    public function auditAction(): string
    {
        return 'procurement.landed_cost.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.landed_costs');
    }

    public function resource(Model $model): JsonResource
    {
        return LandedCostResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            ListColumn::make('method', 'procurement.columns.method', ['method'], fn (array $row) => __('procurement.methods.'.$row['method'])),
            ListColumn::text('description', 'procurement.columns.description'),
            self::named('branch'),
            self::money('base_total'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
