<?php

namespace App\Core\Approvals\Http\Lists;

use App\Core\Approvals\Http\Resources\ApprovalItemResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * APR-04: the approvals inbox (EXP-01): sorted by due time (soonest
 * first, none last) or by when the request was received.
 */
class ApprovalList extends ListDefinition
{
    public function name(): string
    {
        return 'approvals';
    }

    public function auditAction(): string
    {
        return 'core.approval.export';
    }

    public function title(array $filters): string
    {
        return __('approvals.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return ApprovalItemResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'due' => ListSort::by(['due_at'], fn (Builder $query, string $direction) => $query->orderByRaw('due_at '.($direction === 'desc' ? 'desc' : 'asc').' nulls last')),
            'received' => ListSort::column('received_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'due';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('received_at', 'approvals.columns.received_at', ['received_at'],
                fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['received_at'])),
            ListColumn::make('type', 'approvals.columns.type', ['document'], fn (array $row) => $row['document']['type_label']),
            ListColumn::make('number', 'approvals.columns.number', ['document'], fn (array $row) => $row['document']['number']),
            ListColumn::make('title', 'approvals.columns.title', ['document'], fn (array $row) => $row['document']['title']),
            ListColumn::make('amount', 'approvals.columns.amount', ['document'],
                fn (array $row, Model $model, ExportValues $values) => $values->money($row['document']['amount'])),
            ListColumn::make('step', 'approvals.columns.step', ['step'], fn (array $row) => $row['step']['name']),
            ListColumn::make('requester', 'approvals.columns.requester', ['requester'], fn (array $row) => $row['requester']['name'] ?? null),
            ListColumn::make('status', 'approvals.columns.status', ['status'], fn (array $row) => __('approvals.statuses.'.$row['status'])),
            ListColumn::make('due_at', 'approvals.columns.due_at', ['due_at'],
                fn (array $row, Model $model, ExportValues $values) => $values->dateTime($row['due_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('approvals.filters.'.($filters['status'] ?? 'waiting'));

        return $summary;
    }
}
