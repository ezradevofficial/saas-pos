<?php

namespace App\Core\Payments\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Payments\Http\Resources\PaymentIntentResource;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** A company's payment intents (EXP-01): phones masked, never the provider's raw answer. */
class PaymentIntentList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'payment-intents';
    }

    public function auditAction(): string
    {
        return 'core.payment.export';
    }

    public function title(array $filters): string
    {
        return __('payments.intents.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return PaymentIntentResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'status' => ListSort::column('status'),
            'amount' => ListSort::column('amount_minor'),
            'reference' => ListSort::column('reference'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'payments.intents.columns.created_at', ['created_at'],
                fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['created_at'], $this->company->id)),
            ListColumn::make('purpose', 'payments.intents.columns.purpose', ['purpose'], fn (array $row) => __('payments.purposes.'.$row['purpose'])),
            ListColumn::make('mode', 'payments.intents.columns.mode', ['mode'], fn (array $row) => __('payments.modes.'.$row['mode'])),
            ListColumn::make('amount', 'payments.intents.columns.amount', ['amount'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['amount'])),
            ListColumn::text('phone', 'payments.intents.columns.phone'),
            ListColumn::text('reference', 'payments.intents.columns.reference'),
            ListColumn::text('receipt', 'payments.intents.columns.receipt'),
            ListColumn::make('status', 'payments.intents.columns.status', ['status'], fn (array $row) => __('payments.statuses.'.$row['status'])),
            ListColumn::make('verification', 'payments.intents.columns.verification', ['verification'],
                fn (array $row) => $row['verification'] === null ? null : __('payments.verifications.'.$row['verification'])),
            ListColumn::text('message', 'payments.intents.columns.message'),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('payments.filters.'.($filters['status'] ?? 'all'));

        return $summary;
    }
}
