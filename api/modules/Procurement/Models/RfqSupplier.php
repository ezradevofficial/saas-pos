<?php

namespace Modules\Procurement\Models;

use App\Core\MasterData\Parties\Party;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-03: a supplier invited to quote.
 *
 * @property string $rfq_id
 * @property string $party_id
 */
class RfqSupplier extends ProcurementModel
{
    protected $table = 'procurement_rfq_suppliers';

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
