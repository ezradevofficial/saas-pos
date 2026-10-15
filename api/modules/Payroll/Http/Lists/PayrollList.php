<?php

namespace Modules\Payroll\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Modules\Payroll\Support\PayFields;

/**
 * What Payroll's lists share (PAY-25, EXP-01): field rules resource
 * `payroll` (PAY-23), money columns built from the hidden-able `amounts`
 * or `totals`, and the filter summary.
 */
abstract class PayrollList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return PayFields::RESOURCE;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('payroll.statuses.'.$filters['status']);
        }

        return $summary;
    }

    protected static function text(string $key, ?string $field = null): ListColumn
    {
        return ListColumn::text($key, 'payroll.columns.'.$key, $field);
    }

    protected static function date(string $key): ListColumn
    {
        return ListColumn::make($key, 'payroll.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->date($row[$key] ?? null));
    }

    protected static function named(string $key): ListColumn
    {
        return ListColumn::make($key, 'payroll.columns.'.$key, [$key], fn (array $row) => $row[$key]['name'] ?? null);
    }

    /** A money column hidden with $field (`amounts` or `totals`). */
    protected static function money(string $key, string $field = PayFields::AMOUNTS): ListColumn
    {
        return ListColumn::make($key, 'payroll.columns.'.$key, [$key, $field], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
    }

    protected static function status(string $key = 'status', string $prefix = 'payroll.statuses.'): ListColumn
    {
        return ListColumn::make($key, 'payroll.columns.'.$key, [$key], fn (array $row) => $row[$key] === null ? null : __($prefix.$row[$key]));
    }
}
