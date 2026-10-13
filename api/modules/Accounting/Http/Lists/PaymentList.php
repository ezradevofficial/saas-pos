<?php

namespace Modules\Accounting\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Http\Resources\PaymentResource;

/** ACC-10, EXP-01: receipts and payments, newest first. */
class PaymentList extends ListDefinition
{
    public const RELATIONS = ['party', 'bankAccount'];

    public function name(): string
    {
        return 'accounting-payments';
    }

    public function auditAction(): string
    {
        return 'accounting.payment.export';
    }

    public function title(array $filters): string
    {
        return __('accounting.lists.payments');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return PaymentResource::make($model);
    }

    public function sorts(): array
    {
        return ['date' => ListSort::column('date'), 'number' => ListSort::column('number'), 'amount' => ListSort::column('amount_minor', ['amount'])];
    }

    public function defaultSort(): string
    {
        return '-date';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('date', 'accounting.columns.date', ['date'], fn (array $row, Model $model, ExportValues $values) => $values->date($row['date'])),
            ListColumn::text('number', 'accounting.columns.number'),
            ListColumn::make('kind', 'accounting.columns.kind', ['kind'], fn (array $row) => __('accounting.payment_kinds.'.$row['kind'])),
            ListColumn::make('party', 'accounting.columns.party', ['party'], fn (array $row) => $row['party']['name'] ?? null),
            ListColumn::make('bank_account', 'accounting.columns.bank_account', ['bank_account'], fn (array $row) => $row['bank_account']['name'] ?? null),
            ListColumn::make('amount', 'accounting.columns.amount', ['amount'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['amount'])),
            ListColumn::make('unallocated', 'accounting.columns.unallocated', ['unallocated'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['unallocated'])),
            ListColumn::text('reference', 'accounting.columns.reference'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters, archivable: false);
    }
}
