<?php

namespace App\Core\Automation\Http\Lists;

use App\Core\Automation\Http\Resources\AutomationRuleResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** AUTO-01..AUTO-03: the rules list: sort keys and exportable columns (EXP-01). No field rules apply. */
class AutomationRuleList extends ListDefinition
{
    public function name(): string
    {
        return 'automation-rules';
    }

    public function auditAction(): string
    {
        return 'core.automation.export';
    }

    public function title(array $filters): string
    {
        return __('automation.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return AutomationRuleResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            'document_type' => ListSort::column('document_type'),
            'trigger_type' => ListSort::column('trigger_type'),
            'enabled' => ListSort::column('enabled'),
            'created_at' => ListSort::column('created_at'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'automation.columns.name'),
            ListColumn::text('document_type', 'automation.columns.document_type', 'document_type_label'),
            ListColumn::make('company', 'automation.columns.company', ['company_name'],
                fn (array $row) => $row['company_name'] ?? __('automation.all_companies')),
            ListColumn::text('trigger', 'automation.columns.trigger', 'trigger_description'),
            ListColumn::make('status', 'automation.columns.status', ['status'], fn (array $row) => __('automation.statuses.'.$row['status'])),
            ListColumn::text('version', 'automation.columns.version'),
            ListColumn::make('updated_at', 'automation.columns.updated_at', ['updated_at'],
                fn (array $row, Model $rule, ExportValues $values) => $values->dateTime($row['updated_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('automation.statuses.'.($filters['status'] ?? 'active'));

        return $summary;
    }

    public function exportRelations(): array
    {
        return ['company'];
    }
}
