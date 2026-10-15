<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-14: what arrived of one PO line: received, rejected for quality and
 * accepted quantities, batch, expiry, serials, weight; its value (PO
 * currency, base) and the landed cost allocated to it (base, PRC-17).
 *
 * @property string $receipt_id
 * @property string $order_line_id
 * @property ?string $item_id
 * @property ?string $uom_id
 * @property string $received_qty
 * @property string $rejected_qty
 * @property string $accepted_qty
 * @property ?string $batch_number
 * @property list<string> $serials
 * @property ?string $weight
 * @property int $value_minor
 * @property ?int $base_value_minor
 * @property int $landed_minor
 * @property ?bool $on_time
 * @property bool $stock_tracked
 * @property list<array{module: string, code: string}> $receive_exceptions
 */
class ReceiptLine extends ProcurementModel
{
    use HasItemLine;

    protected $table = 'procurement_receipt_lines';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'serials' => 'array', 'expires_on' => 'immutable_date', 'value_minor' => 'integer',
            'base_value_minor' => 'integer', 'landed_minor' => 'integer', 'on_time' => 'boolean',
            'stock_tracked' => 'boolean', 'receive_exceptions' => 'array',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
