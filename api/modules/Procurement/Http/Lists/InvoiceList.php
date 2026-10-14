<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\InvoiceResource;

/** PRC-15, PRC-21: the invoices list and its export, newest first. */
class InvoiceList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'party', 'order', 'creator', 'approver'];

    public function name(): string
    {
        return 'procurement-invoices';
    }

    public function auditAction(): string
    {
        return 'procurement.invoice.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.invoices');
    }

    public function resource(Model $model): JsonResource
    {
        return InvoiceResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            ListColumn::text('supplier_reference', 'procurement.columns.supplier_reference'),
            self::named('party'),
            ListColumn::make('order', 'procurement.columns.order', ['order'], fn (array $row) => $row['order']['number'] ?? null),
            ListColumn::make('invoice_date', 'procurement.columns.invoice_date', ['invoice_date'], fn (array $row, $model, ExportValues $values) => $values->date($row['invoice_date'] ?? null)),
            self::money('total'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
