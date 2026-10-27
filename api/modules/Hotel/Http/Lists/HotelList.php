<?php

namespace Modules\Hotel\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use Closure;

/**
 * What Hotel's lists share (HOT-31, EXP-01): columns labelled `hotel.columns.*`,
 * filters printed on a PDF from the request. Lists carry no field rules resource
 * (guest identity documents are never a list column); their rows are limited to
 * the reader's branches by the controller.
 */
abstract class HotelList extends ListDefinition
{
    public function fieldRules(): ?string
    {
        return null;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('hotel.states.'.$filters['status']);
        }

        return $summary;
    }

    protected static function col(string $key, array $fields, Closure $value): ListColumn
    {
        return ListColumn::make($key, 'hotel.columns.'.$key, $fields, $value);
    }

    protected static function day(string $key): ListColumn
    {
        return self::col($key, [$key], fn (array $row, $model, ExportValues $values) => $values->date($row[$key] ?? null));
    }

    protected static function at(string $key): ListColumn
    {
        return self::col($key, [$key], fn (array $row, $model, ExportValues $values) => $values->dateTime($row[$key] ?? null));
    }

    /** An amount in minor units with the row's currency, e.g. "KES 12,450.00". */
    protected static function amount(string $key, string $field = 'currency'): ListColumn
    {
        return self::col($key, [$key, $field], fn (array $row, $model, ExportValues $values) => isset($row[$key]) ? $values->money(['amount_minor' => (string) $row[$key], 'currency' => $row[$field]]) : null);
    }
}
