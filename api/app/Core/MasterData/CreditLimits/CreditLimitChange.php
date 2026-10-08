<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Audit\Audited;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MD-01, WF-01: a request to change a party's credit limit, decided through
 * the `core.credit_limit_change` flow (CreditLimitChangeType) and applied
 * to the party when approved (CreditLimitChanges::decide).
 *
 * Status: pending (its flow is running) → applied (approved and written to
 * the party), rejected or cancelled. `approved` is the moment between the
 * decision and the party write (never left there unless applying failed);
 * `draft` is reserved for requests saved before submitting. Never deleted
 * (TEN-06); every change audited as `core.credit_limit_change.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $party_id
 * @property string $company_id
 * @property ?string $current_limit_minor
 * @property ?string $current_limit_currency
 * @property string $requested_limit_minor
 * @property string $requested_limit_currency
 * @property string $reason
 * @property string $status
 * @property string $requested_by
 */
class CreditLimitChange extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const APPLIED = 'applied';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED, self::APPLIED];

    /** Statuses of a request still open (one per party at a time). */
    public const OPEN = [self::DRAFT, self::PENDING];

    protected $fillable = [
        'seq', 'number', 'party_id', 'company_id', 'current_limit_minor', 'current_limit_currency',
        'requested_limit_minor', 'requested_limit_currency', 'reason', 'status', 'requested_by',
        'decided_by', 'decided_at', 'applied_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function currentLimit(): ?Money
    {
        return $this->current_limit_minor === null ? null : Money::ofMinor((string) $this->current_limit_minor, $this->current_limit_currency);
    }

    public function requestedLimit(): Money
    {
        return Money::ofMinor((string) $this->requested_limit_minor, $this->requested_limit_currency);
    }

    /** Requested minus current, in the requested currency (no current limit counts as zero). */
    public function increase(): Money
    {
        $current = $this->currentLimit();

        return $current === null ? $this->requestedLimit() : $this->requestedLimit()->minus($current);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
