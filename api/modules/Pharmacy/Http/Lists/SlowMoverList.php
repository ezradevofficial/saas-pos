<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListSort;

/** PHA-24: slow movers per batch: lots with stock and the days since each last sold at its place. */
class SlowMoverList extends LotList
{
    public function name(): string
    {
        return 'pharmacy-slow-movers';
    }

    public function auditAction(): string
    {
        return 'pharmacy.report.slow_movers.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.slow_movers');
    }

    public function sorts(): array
    {
        return [
            ...parent::sorts(),
            'last_sold_at' => ListSort::by(['last_sold_at'], fn ($query, string $direction) => $query->orderByRaw('last_sold_at '.($direction === 'desc' ? 'desc nulls last' : 'asc nulls first'))),
        ];
    }

    public function defaultSort(): string
    {
        return 'last_sold_at';
    }

    public function columns(): array
    {
        return [
            self::item(),
            self::col('batch_number', ['batch_number'], fn (array $row) => $row['batch_number']),
            self::on('expires_on'),
            self::qty('qty', 'qty'),
            self::at('last_sold_at'),
            self::col('days_since_sale', ['days_since_sale'], fn (array $row, $model, ExportValues $values) => $values->integer($row['days_since_sale'] ?? null)),
            self::named('location', 'location'),
        ];
    }
}
