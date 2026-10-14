<?php

namespace Modules\HR\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Modules\HR\Support\Sensitive;

/** What HR's lists share (HR-35, EXP-01): column helpers and the filter summary. */
abstract class HrList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('hr.statuses.'.$filters['status']);
        }

        return $summary;
    }

    protected static function named(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'hr.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function date(string $key, ?string $field = null): ListColumn
    {
        $field ??= $key;

        return ListColumn::make($key, 'hr.columns.'.$key, [$field], fn (array $row, $model, ExportValues $values) => $values->date($row[$field] ?? null));
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'hr.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? $row['company_id'] ?? null));
    }

    protected static function status(): ListColumn
    {
        return ListColumn::make('status', 'hr.columns.status', ['status'], fn (array $row) => __('hr.statuses.'.$row['status']));
    }

    protected static function text(string $key, ?string $field = null): ListColumn
    {
        return ListColumn::text($key, 'hr.columns.'.$key, $field);
    }

    protected static function employee(): ListColumn
    {
        return ListColumn::make('employee', 'hr.columns.employee', ['employee'], fn (array $row) => isset($row['employee']) ? $row['employee']['number'].' '.$row['employee']['name'] : null);
    }

    /** @return list<string> the sensitive list fields */
    protected static function sensitiveFields(): array
    {
        return Sensitive::FIELDS;
    }
}
