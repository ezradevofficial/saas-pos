<?php

namespace Modules\Loyalty\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Http\Resources\PromotionResource;
use Modules\Loyalty\Models\Promotion;

/** LOY-08, EXP-01: promotions, highest priority first. */
class PromotionList extends ListDefinition
{
    public const RELATIONS = ['company'];

    public function name(): string
    {
        return 'loyalty-promotions';
    }

    public function auditAction(): string
    {
        return 'loyalty.promotion.export';
    }

    public function title(array $filters): string
    {
        return __('loyalty.promotion.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return PromotionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            'priority' => ListSort::column('priority'),
            'status' => ListSort::column('status'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-priority';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'loyalty.promotion.columns.name', ['name'], fn (array $row) => $row['name']),
            ListColumn::make('type', 'loyalty.promotion.columns.type', ['type'], fn (array $row) => __('loyalty.promotion.types.'.$row['type'])),
            ListColumn::make('status', 'loyalty.promotion.columns.status', ['status'], fn (array $row) => __('loyalty.promotion.statuses.'.$row['status'])),
            ListColumn::make('priority', 'loyalty.promotion.columns.priority', ['priority'], fn (array $row) => (string) $row['priority']),
            ListColumn::make('company', 'loyalty.promotion.columns.company', ['company'], fn (array $row) => $row['company']['name'] ?? null),
            ListColumn::make('version', 'loyalty.promotion.columns.version', ['version'], fn (array $row) => (string) $row['version']),
            ListColumn::make('updated_at', 'loyalty.promotion.columns.updated_at', ['updated_at'],
                fn (array $row, Promotion $promotion, ExportValues $values) => $values->dateTime($row['updated_at'], $promotion->company_id)),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('loyalty.promotion.statuses.'.$filters['status']);
        }

        return $summary;
    }
}
