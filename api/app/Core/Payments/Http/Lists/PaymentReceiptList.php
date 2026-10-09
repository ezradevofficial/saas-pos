<?php

namespace App\Core\Payments\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Payments\Http\Resources\PaymentReceiptResource;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** Money received on a company's Till or Paybill (C2B), for matching (EXP-01). */
class PaymentReceiptList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'payment-receipts';
    }

    public function auditAction(): string
    {
        return 'core.payment.export';
    }

    public function title(array $filters): string
    {
        return __('payments.receipts.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return PaymentReceiptResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'transacted_at' => ListSort::column('transacted_at'),
            'amount' => ListSort::column('amount_minor'),
            'receipt' => ListSort::column('receipt'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('transacted_at', 'payments.receipts.columns.transacted_at', ['transacted_at'],
                fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['transacted_at'], $this->company->id)),
            ListColumn::text('receipt', 'payments.receipts.columns.receipt'),
            ListColumn::make('amount', 'payments.receipts.columns.amount', ['amount'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['amount'])),
            ListColumn::text('account_reference', 'payments.receipts.columns.account_reference'),
            ListColumn::make('status', 'payments.receipts.columns.status', ['status'], fn (array $row) => __('payments.receipt_statuses.'.$row['status'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('payments.receipt_filters.'.($filters['status'] ?? 'unmatched'));

        return $summary;
    }
}
