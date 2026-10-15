<?php

namespace Modules\Procurement\Models;

/**
 * PRC-03: one thing an RFQ asks prices for.
 *
 * @property string $rfq_id
 * @property ?string $item_id
 * @property string $description
 * @property ?string $uom_id
 * @property string $qty
 * @property ?string $requisition_line_id
 */
class RfqLine extends ProcurementModel
{
    use HasItemLine;

    protected $table = 'procurement_rfq_lines';

    protected function casts(): array
    {
        return ['line_no' => 'integer'];
    }
}
