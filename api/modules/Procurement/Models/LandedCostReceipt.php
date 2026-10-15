<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-17: a GRN a landed cost applies to.
 *
 * @property string $landed_cost_id
 * @property string $receipt_id
 */
class LandedCostReceipt extends ProcurementModel
{
    protected $table = 'procurement_landed_cost_receipts';

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
