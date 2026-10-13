<?php

namespace Modules\Inventory\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\Values;

/**
 * What Inventory's lists share (INV-19, EXP-01): no field rules resource;
 * instead the value columns are left out of an export for a reader who
 * holds `inventory.valuation.view` nowhere (rows the reader may not value
 * show them blank, RBAC-05).
 */
abstract class InventoryList extends ListDefinition
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
            $summary[__('core.list.status')] = __('inventory.statuses.'.$filters['status']);
        }

        return $summary;
    }

    protected static function qty(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'inventory.columns.'.$key, [$field], fn (array $row, $model, ExportValues $values) => $values->decimal($row[$field] ?? null));
    }

    protected static function money(string $key): ListColumn
    {
        return ListColumn::make($key, 'inventory.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
    }

    protected static function named(string $key, string $field): ListColumn
    {
        return ListColumn::make($key, 'inventory.columns.'.$key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function at(string $key): ListColumn
    {
        return ListColumn::make($key, 'inventory.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null, $row['company']['id'] ?? $row['company_id'] ?? null));
    }
}
