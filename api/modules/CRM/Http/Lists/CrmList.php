<?php

namespace Modules\CRM\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;

/**
 * What CRM's lists share (CRM-32, EXP-01, LAY-04): statuses and money
 * printed as the API names them; newest first by default.
 */
abstract class CrmList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('crm.statuses.'.$filters['status']);
        }

        return $summary;
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    protected static function status(): ListColumn
    {
        return ListColumn::make('status', 'crm.columns.status', ['status'], fn (array $row) => __('crm.statuses.'.$row['status']));
    }

    protected static function money(string $key, ?string $field = null): ListColumn
    {
        $field ??= $key;

        return ListColumn::make($key, 'crm.columns.'.$key, [$field], fn (array $row, $model, ExportValues $values) => $values->money($row[$field] ?? null));
    }

    protected static function named(string $key, ?string $field = null): ListColumn
    {
        $field ??= $key;

        return ListColumn::make($key, 'crm.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'crm.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? null));
    }

    protected static function date(string $key): ListColumn
    {
        return ListColumn::make($key, 'crm.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->date($row[$key] ?? null));
    }

    protected static function text(string $key): ListColumn
    {
        return ListColumn::text($key, 'crm.columns.'.$key);
    }
}
