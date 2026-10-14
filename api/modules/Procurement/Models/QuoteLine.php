<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-04, PRC-05: a quoted price for one RFQ line, in the quote's currency
 * and in base currency; `selected` once awarded.
 *
 * @property string $quote_id
 * @property string $rfq_line_id
 * @property string $qty
 * @property int $unit_price_minor
 * @property int $base_unit_price_minor
 * @property ?int $lead_time_days
 * @property bool $selected
 */
class QuoteLine extends ProcurementModel
{
    protected $table = 'procurement_quote_lines';

    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'base_unit_price_minor' => 'integer', 'lead_time_days' => 'integer', 'selected' => 'boolean'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class);
    }
}
