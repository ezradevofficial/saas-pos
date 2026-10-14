<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-07: a bidder's portal session, bound to one tenant (RLS), one tender
 * and one bidder. The bearer token is kept only as its sha256.
 *
 * @property string $bidder_id
 * @property string $tender_id
 * @property string $token_hash
 * @property \Carbon\CarbonImmutable $expires_at
 * @property ?\Carbon\CarbonImmutable $revoked_at
 */
class PortalSession extends ProcurementModel
{
    protected $table = 'procurement_portal_sessions';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(Bidder::class);
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }
}
