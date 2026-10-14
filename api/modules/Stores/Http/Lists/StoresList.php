<?php

namespace Modules\Stores\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Illuminate\Http\Request;
use Modules\Stores\Http\Resources\Values;

/**
 * What Stores' lists share (STR-18, EXP-01): no field rules resource;
 * instead the value columns are left out of an export for a reader who
 * holds `stores.value.view` nowhere (rows the reader may not value show
 * them blank, RBAC-05).
 */
abstract class StoresList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function hiddenFields(Request $request): array
    {
        return Values::anywhere($request) ? [] : Values::FIELDS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('stores.statuses.'.$filters['status']);
        }

        return $summary;
    }

    protected static function qty(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'stores.columns.'.$key, [$field], fn (array $row, $model, ExportValues $values) => $values->decimal($row[$field] ?? null));
    }

    protected static function money(string $key): ListColumn
    {
        return ListColumn::make($key, 'stores.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
    }

    protected static function named(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'stores.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'stores.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? null));
    }

    protected static function status(): ListColumn
    {
        return ListColumn::make('status', 'stores.columns.status', ['status'], fn (array $row) => __('stores.statuses.'.$row['status']));
    }
}
