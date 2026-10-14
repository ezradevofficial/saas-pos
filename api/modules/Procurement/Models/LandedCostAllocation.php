<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-17: a landed cost's share of one GRN line (base minor units), its
 * basis (value, quantity or weight) and whether the GRN was already posted.
 *
 * @property string $landed_cost_id
 * @property string $receipt_id
 * @property string $receipt_line_id
 * @property string $basis
 * @property int $base_amount_minor
 * @property bool $after_receipt
 */
class LandedCostAllocation extends ProcurementModel
{
    protected $table = 'procurement_landed_cost_allocations';

    protected function casts(): array
    {
        return ['base_amount_minor' => 'integer', 'after_receipt' => 'boolean'];
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(ReceiptLine::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
