<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PRC-07, PRC-08: who may bid on a tender: an invited supplier party or an
 * outsider (no party until awarded) that registered on an open tender.
 * The link token is kept only as its sha256 (`token_hash`, never shown,
 * never exported). Audited without the hash (PRC-24).
 *
 * @property string $tender_id
 * @property ?string $party_id
 * @property string $name
 * @property ?string $tax_id
 * @property string $email
 * @property string $source
 * @property ?string $token_hash
 * @property ?CarbonImmutable $token_expires_at
 * @property ?CarbonImmutable $removed_at
 */
class Bidder extends ProcurementModel
{
    use Audited;

    public const INVITED = 'invited';

    public const REGISTERED = 'registered';

    protected $table = 'procurement_tender_bidders';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'bidder';

    protected $hidden = ['token_hash'];

    protected array $auditHidden = ['token_hash', 'token_expires_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['token_expires_at' => 'immutable_datetime', 'link_sent_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function bid(): HasOne
    {
        return $this->hasOne(Bid::class);
    }
}
