<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-13: a PO line with its amounts, and how much of it was received,
 * rejected and invoiced (PRC-14, PRC-15).
 *
 * @property string $order_id
 * @property ?string $item_id
 * @property string $description
 * @property ?string $uom_id
 * @property string $qty
 * @property int $unit_price_minor
 * @property ?string $tax_code_id
 * @property ?string $tax_rate
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $received_qty
 * @property string $rejected_qty
 * @property string $invoiced_qty
 * @property ?string $cost_centre_id
 * @property ?string $project_id
 */
class OrderLine extends ProcurementModel
{
    use HasItemLine;

    protected $table = 'procurement_order_lines';

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'unit_price_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
