<?php

namespace Modules\Accounting\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Http\Resources\JournalResource;

/** ACC-03, EXP-01: a company's journals, newest first. */
class JournalList extends ListDefinition
{
    public function name(): string
    {
        return 'accounting-journals';
    }

    public function auditAction(): string
    {
        return 'accounting.journal.export';
    }

    public function title(array $filters): string
    {
        return __('accounting.lists.journals');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return JournalResource::make($model);
    }

    public function sorts(): array
    {
        return ['date' => ListSort::column('date'), 'number' => ListSort::column('number'), 'total' => ListSort::column('total_base_minor', ['total'])];
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
            ListColumn::make('kind', 'accounting.columns.kind', ['kind'], fn (array $row) => __('accounting.journal_kinds.'.$row['kind'])),
            ListColumn::make('status', 'accounting.columns.status', ['status'], fn (array $row) => __('accounting.journal_statuses.'.$row['status'])),
            ListColumn::text('reference', 'accounting.columns.reference'),
            ListColumn::text('memo', 'accounting.columns.memo'),
            ListColumn::make('total', 'accounting.columns.total', ['total'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['total'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters, archivable: false);
    }
}
