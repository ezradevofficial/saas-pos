<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;

/**
 * What Procurement's lists share (PRC-21, EXP-01, ADR 008): no field rules
 * resource; statuses and money printed as the API names them; newest first.
 */
abstract class ProcurementList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('procurement.statuses.'.$filters['status']);
        }

        return $summary;
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    protected static function status(): ListColumn
    {
        return ListColumn::make('status', 'procurement.columns.status', ['status'], fn (array $row) => __('procurement.statuses.'.$row['status']));
    }

    protected static function money(string $key): ListColumn
    {
        return ListColumn::make($key, 'procurement.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
    }

    protected static function named(string $key, ?string $field = null): ListColumn
    {
        $field ??= $key;

        return ListColumn::make($key, 'procurement.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'procurement.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? null));
    }

    protected static function text(string $key): ListColumn
    {
        return ListColumn::text($key, 'procurement.columns.'.$key);
    }
}
