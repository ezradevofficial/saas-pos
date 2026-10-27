<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Closure;

/**
 * What Pharmacy's lists share (PHA-24, EXP-01): no field rules resource (the
 * lists show quantities and dates, never costs; personal data lists need
 * their own permission), columns labelled `pharmacy.columns.*`.
 */
abstract class PharmacyList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('pharmacy.states.'.$filters['status']);
        }

        return $summary;
    }

    protected static function col(string $key, array $fields, Closure $value): ListColumn
    {
        return ListColumn::make($key, 'pharmacy.columns.'.$key, $fields, $value);
    }

    protected static function qty(string $key, string $field): ListColumn
    {
        return self::col($key, [$field], fn (array $row, $model, ExportValues $values) => $values->decimal($row[$field] ?? null));
    }

    protected static function named(string $key, string $field): ListColumn
    {
        return self::col($key, [$field], fn (array $row) => $row[$field]['name'] ?? null);
    }

    protected static function item(): ListColumn
    {
        return self::col('item', ['item'], fn (array $row) => isset($row['item']) ? trim(($row['item']['name'] ?? '').' ('.($row['item']['code'] ?? '').')') : null);
    }

    protected static function at(string $key): ListColumn
    {
        return self::col($key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null));
    }

    protected static function on(string $key): ListColumn
    {
        return self::col($key, [$key], fn (array $row, $model, ExportValues $values) => $values->date($row[$key] ?? null));
    }
}
