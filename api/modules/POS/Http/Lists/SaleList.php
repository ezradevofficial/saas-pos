<?php

namespace Modules\POS\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\POS\Http\Resources\SaleResource;
use Modules\POS\Models\Sale;

/** POS-12, EXP-01: sales, newest first. */
class SaleList extends ListDefinition
{
    public const RELATIONS = ['company', 'branch', 'location', 'device', 'cashier', 'customer'];

    public function name(): string
    {
        return 'pos-sales';
    }

    public function auditAction(): string
    {
        return 'pos.sale.export';
    }

    public function title(array $filters): string
    {
        return __('pos.sale.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return SaleResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'sold_at' => ListSort::column('sold_at'),
            'receipt_number' => ListSort::column('receipt_number'),
            'total' => ListSort::column('total_minor', ['total']),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-sold_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('receipt_number', 'pos.sale.columns.receipt_number'),
            ListColumn::make('sold_at', 'pos.sale.columns.sold_at', ['sold_at'],
                fn (array $row, Sale $sale, ExportValues $values) => $values->dateTime($row['sold_at'], $sale->company_id)),
            ListColumn::make('status', 'pos.sale.columns.status', ['status'], fn (array $row) => __('pos.sale.statuses.'.$row['status'])),
            ListColumn::make('location', 'pos.sale.columns.location', ['location'], fn (array $row) => $row['location']['name'] ?? null),
            ListColumn::make('cashier', 'pos.sale.columns.cashier', ['cashier'], fn (array $row) => $row['cashier']['name'] ?? null),
            ListColumn::make('customer', 'pos.sale.columns.customer', ['customer'], fn (array $row) => $row['customer']['name'] ?? null),
            ListColumn::make('total', 'pos.sale.columns.total', ['total'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['total'])),
            ListColumn::make('tax', 'pos.sale.columns.tax', ['tax'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['tax'])),
            ListColumn::make('base_total', 'pos.sale.columns.base_total', ['base_total'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['base_total'])),
            ListColumn::make('flags', 'pos.sale.columns.flags', ['flags'], fn (array $row) => implode(', ', array_unique(array_column($row['flags'] ?? [], 'code')))),
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
            $summary[__('core.list.status')] = __('pos.sale.statuses.'.$filters['status']);
        }

        foreach (['from', 'to'] as $key) {
            if (isset($filters[$key])) {
                $summary[__("pos.sale.filters.{$key}")] = (string) $values->date($filters[$key]);
            }
        }

        return $summary;
    }
}
