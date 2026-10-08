<?php

namespace App\Core\MasterData\CreditLimits\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\CreditLimits\Http\Resources\CreditLimitChangeResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Credit limit change requests (MD-01, WF-01; EXP-01): newest first by
 * default. Amount columns follow the party's field rules (RBAC-05).
 */
class CreditLimitChangeList extends ListDefinition
{
    public function name(): string
    {
        return 'credit-limit-changes';
    }

    public function auditAction(): string
    {
        return 'core.credit_limit_change.export';
    }

    public function title(array $filters): string
    {
        return __('core.credit_limit_change.list_title');
    }

    public function fieldRules(): string
    {
        return CreditLimitChangeAccess::FIELD_RULES;
    }

    public function fieldSources(): array
    {
        return CreditLimitChangeResource::SOURCES;
    }

    public function resource(Model $model): JsonResource
    {
        return CreditLimitChangeResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('seq'),
            'status' => ListSort::column('status'),
            'created_at' => ListSort::column('created_at'),
            'decided_at' => ListSort::column('decided_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-number';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('number', 'core.credit_limit_change.columns.number'),
            ListColumn::make('party', 'core.credit_limit_change.columns.party', ['party'], fn (array $row) => $row['party']['name']),
            ListColumn::make('company', 'core.credit_limit_change.columns.company', ['company'], fn (array $row) => $row['company']['name']),
            ListColumn::make('current_limit', 'core.credit_limit_change.columns.current_limit', ['current_limit'],
                fn (array $row, Model $model, ExportValues $values) => $values->money($row['current_limit'])),
            ListColumn::make('requested_limit', 'core.credit_limit_change.columns.requested_limit', ['requested_limit'],
                fn (array $row, Model $model, ExportValues $values) => $values->money($row['requested_limit'])),
            ListColumn::make('increase', 'core.credit_limit_change.columns.increase', ['increase'],
                fn (array $row, Model $model, ExportValues $values) => $values->money($row['increase'])),
            ListColumn::text('reason', 'core.credit_limit_change.columns.reason'),
            ListColumn::make('status', 'core.credit_limit_change.columns.status', ['status'],
                fn (array $row) => __('core.credit_limit_change.statuses.'.$row['status'])),
            ListColumn::make('requested_by', 'core.credit_limit_change.columns.requested_by', ['requested_by'], fn (array $row) => $row['requested_by']['name']),
            ListColumn::make('created_at', 'core.credit_limit_change.columns.created_at', ['created_at'],
                fn (array $row, CreditLimitChange $change, ExportValues $values) => $values->dateTime($row['created_at'], $change->company_id)),
            ListColumn::make('decided_at', 'core.credit_limit_change.columns.decided_at', ['decided_at'],
                fn (array $row, CreditLimitChange $change, ExportValues $values) => $values->dateTime($row['decided_at'], $change->company_id)),
        ];
    }

    public function exportRelations(): array
    {
        return ['party', 'company', 'requester', 'decider'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status'])) {
            $summary[__('core.list.status')] = __('core.credit_limit_change.statuses.'.$filters['status']);
        }

        return $summary;
    }
}
