<?php

namespace App\Core\Automation\Http\Lists;

use App\Core\Automation\Http\Resources\AutomationRunResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** AUTO-05: the run log: sort keys and exportable columns (EXP-01). Newest first by default. */
class AutomationRunList extends ListDefinition
{
    public function name(): string
    {
        return 'automation-runs';
    }

    public function auditAction(): string
    {
        return 'core.automation.export';
    }

    public function title(array $filters): string
    {
        return __('automation.runs_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return AutomationRunResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'outcome' => ListSort::column('outcome'),
            'trigger_type' => ListSort::column('trigger_type'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'automation.columns.created_at', ['created_at'],
                fn (array $row, Model $run, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::text('rule', 'automation.columns.rule', 'rule_name'),
            ListColumn::text('rule_version', 'automation.columns.version'),
            ListColumn::make('trigger', 'automation.columns.trigger', ['trigger_type'], fn (array $row) => __('automation.triggers.types.'.$row['trigger_type'])),
            ListColumn::text('document_id', 'automation.columns.document'),
            ListColumn::make('outcome', 'automation.columns.outcome', ['outcome'], fn (array $row) => __('automation.outcomes.'.$row['outcome'])),
            ListColumn::text('attempts', 'automation.columns.attempts'),
            ListColumn::text('error', 'automation.columns.error'),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return isset($filters['outcome']) ? [__('automation.columns.outcome') => __('automation.outcomes.'.$filters['outcome'])] : [];
    }

    public function exportRelations(): array
    {
        return ['rule', 'deliveries'];
    }
}
