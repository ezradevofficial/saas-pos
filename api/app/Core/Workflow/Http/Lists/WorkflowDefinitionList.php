<?php

namespace App\Core\Workflow\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Workflow\Http\Resources\WorkflowDefinitionResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** WF-02, spec 6.4: the flows list: sort keys and exportable columns (EXP-01). No field rules apply. */
class WorkflowDefinitionList extends ListDefinition
{
    public function name(): string
    {
        return 'workflows';
    }

    public function auditAction(): string
    {
        return 'core.workflow.export';
    }

    public function title(array $filters): string
    {
        return __('workflow.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return WorkflowDefinitionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'document_type' => ListSort::column('document_type'),
            'company' => ListSort::by(['company_name'], fn ($query, string $direction) => $query->orderByRaw(
                '(select name from companies where companies.id = workflow_definitions.company_id) '.($direction === 'asc' ? 'asc nulls first' : 'desc nulls last'),
            )),
            'created_at' => ListSort::column('created_at'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'document_type';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('document_type', 'workflow.columns.document_type', 'document_type_label'),
            ListColumn::make('company', 'workflow.columns.company', ['company_name'],
                fn (array $row) => $row['company_name'] ?? __('workflow.all_companies')),
            ListColumn::make('published', 'workflow.columns.published', ['published'],
                fn (array $row) => $row['published'] === null ? null : 'v'.$row['published']['version']),
            ListColumn::make('draft', 'workflow.columns.draft', ['draft'],
                fn (array $row) => $row['draft'] === null ? null : 'v'.$row['draft']['version']),
            ListColumn::make('updated_at', 'workflow.columns.updated_at', ['updated_at'],
                fn (array $row, Model $definition, ExportValues $values) => $values->dateTime($row['updated_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters, archivable: false);
    }

    public function exportRelations(): array
    {
        return ['company', 'published', 'draft'];
    }
}
