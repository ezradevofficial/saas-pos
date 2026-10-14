<?php

namespace Modules\Accounting\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Http\Resources\DocumentResource;

/** ACC-09, EXP-01: invoices and credit notes, newest first. */
class DocumentList extends ListDefinition
{
    public const RELATIONS = ['party'];

    public function name(): string
    {
        return 'accounting-documents';
    }

    public function auditAction(): string
    {
        return 'accounting.document.export';
    }

    public function title(array $filters): string
    {
        return __('accounting.lists.documents');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return DocumentResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'issue_date' => ListSort::column('issue_date'), 'due_date' => ListSort::column('due_date'),
            'number' => ListSort::column('number'), 'total' => ListSort::column('total_minor', ['total']),
        ];
    }

    public function defaultSort(): string
    {
        return '-issue_date';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('issue_date', 'accounting.columns.issue_date', ['issue_date'], fn (array $row, Model $model, ExportValues $values) => $values->date($row['issue_date'])),
            ListColumn::text('number', 'accounting.columns.number'),
            ListColumn::make('kind', 'accounting.columns.kind', ['kind'], fn (array $row) => __('accounting.document_kinds.'.$row['kind'])),
            ListColumn::make('party', 'accounting.columns.party', ['party'], fn (array $row) => $row['party']['name'] ?? null),
            ListColumn::make('due_date', 'accounting.columns.due_date', ['due_date'], fn (array $row, Model $model, ExportValues $values) => $values->date($row['due_date'])),
            ListColumn::make('status', 'accounting.columns.status', ['status'], fn (array $row) => __('accounting.document_statuses.'.$row['status'])),
            ListColumn::make('total', 'accounting.columns.total', ['total'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['total'])),
            ListColumn::make('open', 'accounting.columns.open', ['open'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['open'])),
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
