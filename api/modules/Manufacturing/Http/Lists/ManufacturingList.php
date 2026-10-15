<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Illuminate\Http\Request;
use Modules\Manufacturing\Http\Resources\Costs;

/**
 * What Manufacturing's lists share (MFG-27, EXP-01): no field rules
 * resource; instead the cost columns are left out of an export for a
 * reader who holds `manufacturing.cost.view` nowhere (rows the reader may
 * not cost show them blank, RBAC-05).
 */
abstract class ManufacturingList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function hiddenFields(Request $request): array
    {
        return Costs::anywhere($request) ? [] : Costs::FIELDS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('manufacturing.statuses.'.$filters['status']);
        }

        foreach (['from', 'to'] as $key) {
            if (isset($filters[$key])) {
                $summary[__('manufacturing.columns.'.$key)] = (string) $filters[$key];
            }
        }

        return $summary;
    }

    protected static function qty(string $key, ?string $field = null): ListColumn
    {
        $field ??= $key;

        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$field], fn (array $row, $model, ExportValues $values) => $values->decimal($row[$field] ?? null));
    }

    protected static function money(string $key): ListColumn
    {
        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
    }

    protected static function named(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function code(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$field], fn (array $row) => $row[$field]['code'] ?? null);
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? null));
    }

    protected static function enum(string $key, string $prefix): ListColumn
    {
        return ListColumn::make($key, 'manufacturing.columns.'.$key, [$key], fn (array $row) => isset($row[$key]) ? __($prefix.'.'.$row[$key]) : null);
    }
}
