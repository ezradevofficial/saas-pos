<?php

namespace Modules\CRM\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\PriceList;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM-17: a quote for a customer, approved through the `crm.quote` flow,
 * in any active currency with its rate and base amounts frozen at
 * submission (CUR-04). Cancelled, never deleted.
 *
 * @property ?string $number
 * @property string $party_id
 * @property ?string $opportunity_id
 * @property string $status
 * @property string $currency
 * @property ?string $price_list_id
 * @property bool $tax_inclusive
 * @property CarbonImmutable $quote_date
 * @property CarbonImmutable $valid_until
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property ?FxSnapshot $fx
 * @property ?string $base_currency
 * @property ?int $base_total_minor
 * @property string $max_discount_percent
 * @property bool $needs_approval
 * @property string $requested_by
 * @property ?string $converted_to
 * @property ?array $converted_ref
 */
class Quote extends ScopedRecord
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CONVERTED = 'converted';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::SENT, self::ACCEPTED, self::DECLINED, self::CONVERTED, self::CANCELLED];

    /** CRM-22: what may be printed for the customer, emailed and shared. */
    public const RELEASED = [self::APPROVED, self::SENT, self::ACCEPTED, self::CONVERTED];

    /** CRM-24: the conversion targets; only `invoice` exists today. */
    public const TARGETS = ['invoice', 'pos_order', 'ecommerce_order'];

    protected $table = 'crm_quotes';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'tax_inclusive' => 'boolean', 'needs_approval' => 'boolean', 'converted_ref' => 'array',
            'quote_date' => 'immutable_date', 'valid_until' => 'immutable_date',
            'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'declined_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'base_subtotal_minor' => 'integer', 'base_discount_minor' => 'integer', 'base_tax_minor' => 'integer', 'base_total_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** CRM-23: past its validity date (in the company's time zone). */
    public function isExpired(string $timezone): bool
    {
        return in_array($this->status, [self::APPROVED, self::SENT], true)
            && $this->valid_until->toDateString() < CarbonImmutable::now($timezone)->toDateString();
    }
}
