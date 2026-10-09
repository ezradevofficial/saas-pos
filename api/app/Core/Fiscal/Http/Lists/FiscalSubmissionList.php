<?php

namespace App\Core\Fiscal\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Fiscal\Http\Resources\FiscalSubmissionResource;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** A company's fiscal submissions (EXP-01): the queue as the back office sees it. */
class FiscalSubmissionList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'fiscal-submissions';
    }

    public function auditAction(): string
    {
        return 'core.fiscal.export';
    }

    public function title(array $filters): string
    {
        return __('fiscal.submissions.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return FiscalSubmissionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'invoice_number' => ListSort::column('invoice_no'),
            'status' => ListSort::column('status'),
            'attempts' => ListSort::column('attempts'),
            'next_attempt_at' => ListSort::column('next_attempt_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'fiscal.submissions.columns.created_at', ['created_at'],
                fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['created_at'], $this->company->id)),
            ListColumn::make('document_type', 'fiscal.submissions.columns.document_type', ['document_type'], fn (array $row) => __('fiscal.document_types.'.$row['document_type'])),
            ListColumn::text('document_number', 'fiscal.submissions.columns.document_number'),
            ListColumn::text('invoice_number', 'fiscal.submissions.columns.invoice_number'),
            ListColumn::make('status', 'fiscal.submissions.columns.status', ['status'], fn (array $row) => __('fiscal.statuses.'.$row['status'])),
            ListColumn::text('attempts', 'fiscal.submissions.columns.attempts'),
            ListColumn::text('error', 'fiscal.submissions.columns.error'),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('fiscal.filters.'.($filters['status'] ?? 'all'));

        return $summary;
    }
}
