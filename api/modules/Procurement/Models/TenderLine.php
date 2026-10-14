<?php

namespace Modules\Procurement\Models;

/**
 * PRC-06: one thing a tender asks bids for.
 *
 * @property string $tender_id
 * @property ?string $item_id
 * @property string $description
 * @property ?string $uom_id
 * @property string $qty
 */
class TenderLine extends ProcurementModel
{
    use HasItemLine;

    protected $table = 'procurement_tender_lines';

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }
}
