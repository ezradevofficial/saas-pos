<?php

namespace App\Core\Billing\Http\Lists;

use App\Core\Billing\Http\Resources\BillingInvoiceResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** SUB-03: the tenant's invoices from the platform (its read model), as a list and export (EXP-01). */
class BillingInvoiceList extends ListDefinition
{
    public function name(): string
    {
        return 'billing-invoices';
    }

    public function auditAction(): string
    {
        return 'core.subscription.invoices_export';
    }

    public function title(array $filters): string
    {
        return __('billing.invoices.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return BillingInvoiceResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'issued_at' => ListSort::column('issued_at'),
            'number' => ListSort::column('number'),
            'due_at' => ListSort::column('due_at'),
            'total' => ListSort::column('total_minor'),
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
            ListColumn::text('number', 'billing.invoices.columns.number'),
            ListColumn::make('issued_at', 'billing.invoices.columns.issued_at', ['issued_at'], fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['issued_at'])),
            ListColumn::make('kind', 'billing.invoices.columns.kind', ['kind'], fn (array $row) => __('billing.invoices.kinds.'.$row['kind'])),
            ListColumn::make('period', 'billing.invoices.columns.period', ['period_start', 'period_end'], fn (array $row) => $row['period_start'].' – '.$row['period_end']),
            ListColumn::make('total', 'billing.invoices.columns.total', ['total'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['total'])),
            ListColumn::make('due_at', 'billing.invoices.columns.due_at', ['due_at'], fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['due_at'])),
            ListColumn::make('status', 'billing.invoices.columns.status', ['status'], fn (array $row) => __('billing.invoices.statuses.'.$row['status'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('billing.invoices.filters.'.($filters['status'] ?? 'all'));

        return $summary;
    }
}
