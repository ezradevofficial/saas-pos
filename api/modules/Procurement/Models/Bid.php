<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-09: a sealed bid. Until its tender is opened only `sealed_payload`
 * (ciphertext under the tender's key) and its `fingerprint` hold the
 * content; the plain columns stay null (a database check). The ciphertext
 * is hidden from arrays, JSON and the audit log, and never exported.
 *
 * @property string $tender_id
 * @property string $bidder_id
 * @property string $status
 * @property int $version
 * @property string $fingerprint
 * @property string $sealed_payload
 * @property ?string $currency
 * @property ?FxSnapshot $fx
 * @property ?int $total_minor
 * @property ?int $base_total_minor
 * @property ?array $lines
 * @property ?string $technical_response
 * @property ?int $technical_score_bp
 * @property ?int $financial_score_bp
 * @property ?int $combined_score_bp
 * @property ?int $rank
 * @property ?string $outcome
 */
class Bid extends ProcurementModel
{
    use Audited;

    public const SUBMITTED = 'submitted';

    public const WITHDRAWN = 'withdrawn';

    public const OPENED = 'opened';

    public const TAMPERED = 'tampered';

    public const AWARDED = 'awarded';

    public const REGRETTED = 'regretted';

    public const DISQUALIFIED = 'disqualified';

    protected $table = 'procurement_bids';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'bid';

    protected $hidden = ['sealed_payload'];

    /** PRC-09, PRC-24: sealed content never reaches the audit log; opened values are audited by the opening. */
    protected array $auditHidden = ['sealed_payload', 'lines', 'technical_response', 'total_minor', 'base_total_minor', 'currency', 'fx_rate', 'fx_rate_base', 'fx_rate_quote', 'fx_rate_kind', 'fx_rate_effective_at'];

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'lines' => 'array', 'submitted_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime', 'total_minor' => 'integer', 'base_total_minor' => 'integer', 'version' => 'integer',
            'complete' => 'boolean', 'technical_score_bp' => 'integer', 'financial_score_bp' => 'integer', 'combined_score_bp' => 'integer', 'rank' => 'integer',
        ];
    }

    public function isOpened(): bool
    {
        return $this->status === self::OPENED;
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(Bidder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(BidFile::class)->whereNull('removed_at');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(BidScore::class);
    }
}
