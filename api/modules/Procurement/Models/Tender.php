<?php

namespace Modules\Procurement\Models;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-06: a tender, open or invited, with sealed bids (PRC-09) opened by a
 * committee (PRC-10), scored (PRC-11) and awarded. `seal_secret` is the
 * tender's own key, encrypted with the application key: hidden from every
 * array, JSON and audit entry, and never exported (EXP-02).
 *
 * @property string $title
 * @property string $kind
 * @property string $currency
 * @property CarbonImmutable $deadline_at
 * @property CarbonImmutable $opening_at
 * @property int $technical_weight
 * @property int $financial_weight
 * @property string $pass_mark
 * @property string $seal_secret
 * @property ?string $public_token_hash
 * @property ?CarbonImmutable $opened_at
 * @property ?string $awarded_bid_id
 * @property string $created_by
 */
class Tender extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const OPENED = 'opened';

    public const EVALUATED = 'evaluated';

    public const AWARDED = 'awarded';

    public const CANCELLED = 'cancelled';

    /** Derived for lists: published and past its deadline. */
    public const CLOSED = 'closed';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::OPENED, self::EVALUATED, self::AWARDED, self::CANCELLED];

    public const OPEN = 'open';

    public const INVITED = 'invited';

    protected $table = 'procurement_tenders';

    protected string $auditResource = 'tender';

    protected $hidden = ['seal_secret', 'public_token_hash', 'public_token_secret'];

    protected array $auditHidden = ['seal_secret', 'public_token_hash', 'public_token_secret'];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'immutable_datetime', 'opening_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime', 'evaluated_at' => 'immutable_datetime', 'awarded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'technical_weight' => 'integer', 'financial_weight' => 'integer',
        ];
    }

    /** Whether the deadline has passed (no bid changes any more). */
    public function closedForBids(?CarbonImmutable $at = null): bool
    {
        return ($at ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->deadline_at);
    }

    /** The status lists show: `closed` for a published tender past its deadline. */
    public function shownStatus(): string
    {
        return $this->status === self::PUBLISHED && $this->closedForBids() ? self::CLOSED : $this->status;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TenderLine::class)->orderBy('line_no');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(TenderCriterion::class)->orderBy('line_no');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TenderMember::class);
    }

    public function bidders(): HasMany
    {
        return $this->hasMany(Bidder::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function openings(): HasMany
    {
        return $this->hasMany(TenderOpening::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
