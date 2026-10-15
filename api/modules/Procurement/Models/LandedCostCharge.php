<?php

namespace Modules\Procurement\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Parties\Party;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-17: one charge of a landed cost, in its currency, with its base
 * amount at the company's rate on the document date once posted.
 *
 * @property string $landed_cost_id
 * @property string $description
 * @property ?string $party_id
 * @property ?string $bill_reference
 * @property string $currency
 * @property int $amount_minor
 * @property ?FxSnapshot $fx
 * @property ?int $base_amount_minor
 */
class LandedCostCharge extends ProcurementModel
{
    protected $table = 'procurement_landed_cost_charges';

    protected function casts(): array
    {
        return ['fx' => FxSnapshot::class.':fx', 'line_no' => 'integer', 'amount_minor' => 'integer', 'base_amount_minor' => 'integer'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
