<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Parties\Party;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-04: a supplier's quote for an RFQ, in its currency, with its prices
 * also in base currency at the company's rate on the quote date (CUR-04).
 *
 * @property string $rfq_id
 * @property string $party_id
 * @property string $currency
 * @property ?FxSnapshot $fx
 * @property string $base_currency
 * @property string $status
 * @property int $total_minor
 * @property int $base_total_minor
 */
class Quote extends ProcurementModel
{
    use Audited;

    public const RECEIVED = 'received';

    public const AWARDED = 'awarded';

    public const NOT_AWARDED = 'not_awarded';

    protected $table = 'procurement_quotes';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'quote';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'quote_date' => 'immutable_date', 'valid_until' => 'immutable_date',
            'total_minor' => 'integer', 'base_total_minor' => 'integer',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }
}
