<?php

namespace Modules\Ecommerce\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ecommerce\Http\Resources\OrderResource;

/** ECOM-22, EXP-01, LAY-04: the orders list and its export. */
class OrderList extends ListDefinition
{
    public function name(): string
    {
        return 'ecommerce-orders';
    }

    public function auditAction(): string
    {
        return 'ecommerce.order.export';
    }

    public function title(array $filters): string
    {
        return __('ecommerce.lists.orders');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return OrderResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'placed_at' => ListSort::column('placed_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
            'total' => ListSort::column('total_minor'),
        ];
    }

    public function defaultSort(): string
    {
        return '-placed_at';
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('ecommerce.statuses.'.$filters['status']);
        }

        return $summary;
    }

    public function columns(): array
    {
        $label = fn (string $group) => fn (string $key) => ListColumn::make($key, 'ecommerce.columns.'.$key, [$key], fn (array $row) => __("ecommerce.{$group}.".$row[$key]));

        return [
            ListColumn::text('number', 'ecommerce.columns.number'),
            ListColumn::make('store', 'ecommerce.columns.store', ['store'], fn (array $row) => $row['store']['name'] ?? null),
            $label('statuses')('status'),
            $label('payment_statuses')('payment_status'),
            $label('channels')('channel'),
            $label('fulfilments')('fulfilment'),
            ListColumn::text('contact_name', 'ecommerce.columns.contact_name'),
            ListColumn::text('contact_phone', 'ecommerce.columns.contact_phone'),
            ListColumn::make('total', 'ecommerce.columns.total', ['total_minor', 'currency'],
                fn (array $row, $model, ExportValues $values) => $values->money(['amount_minor' => $row['total_minor'] ?? null, 'currency' => $row['currency']])),
            ListColumn::make('placed_at', 'ecommerce.columns.placed_at', ['placed_at'],
                fn (array $row, $model, ExportValues $values) => $values->dateTime($row['placed_at'] ?? null, $row['company']['id'] ?? null)),
        ];
    }
}
