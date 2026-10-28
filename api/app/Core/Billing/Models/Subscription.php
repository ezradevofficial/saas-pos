<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SUB-02..SUB-07: a tenant's subscription (one per tenant). The plan is
 * the selection the PriceCalculator prices (modules, quantities per unit,
 * cycle, bundle); `price_ids` keeps the catalogue rows the current period
 * was sold at, so price changes reach it only at renewal. Downgrades and
 * cycle changes wait in `pending_change` for the period end.
 *
 * Statuses: trial (the free trial; subscribed when its first period is
 * already set, starting at the trial end), active, past_due (an invoice unpaid
 * after its due date: the grace period), read_only (grace over: writes
 * refused, the POS keeps selling until `pos_stops_at`), suspended and
 * cancelled (set by staff; not reached by dunning). Every change is
 * audited (`core.subscription.*`, AUD-01).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $country
 * @property string $currency
 * @property string $cycle
 * @property string|null $bundle
 * @property list<string> $modules
 * @property array<string, int> $quantities
 * @property array<string, string> $price_ids
 * @property string|null $coupon_id
 * @property string|null $coupon_code
 * @property Carbon|null $coupon_until
 * @property string $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property int $period_amount_minor
 * @property int $monthly_amount_minor
 * @property array<string, mixed>|null $pending_change
 * @property Carbon|null $past_due_since
 * @property Carbon|null $read_only_since
 * @property Carbon|null $pos_stops_at
 * @property int|null $grace_days
 * @property list<string> $reminders_sent
 * @property string|null $default_payment_method_id
 * @property Carbon|null $overage_billed_through
 */
class Subscription extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const READ_ONLY = 'read_only';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::TRIAL, self::ACTIVE, self::PAST_DUE, self::READ_ONLY, self::SUSPENDED, self::CANCELLED];

    /** Statuses in which writes are refused (EnforceSubscription). */
    public const WRITES_REFUSED = [self::READ_ONLY, self::SUSPENDED];

    protected $fillable = [
        'country', 'currency', 'cycle', 'bundle', 'modules', 'quantities', 'price_ids', 'coupon_id', 'coupon_code',
        'coupon_until', 'status', 'trial_ends_at', 'current_period_start', 'current_period_end', 'period_amount_minor',
        'monthly_amount_minor', 'pending_change', 'past_due_since', 'read_only_since', 'pos_stops_at', 'grace_days',
        'reminders_sent', 'default_payment_method_id', 'overage_billed_through', 'status_before_suspension',
    ];

    /** The reminder log is bookkeeping, not a change of the subscription. */
    protected array $auditHidden = ['reminders_sent'];

    protected $attributes = [
        'modules' => '[]',
        'quantities' => '{}',
        'price_ids' => '{}',
        'reminders_sent' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'quantities' => 'array',
            'price_ids' => 'array',
            'pending_change' => 'array',
            'reminders_sent' => 'array',
            'coupon_until' => 'date',
            'overage_billed_through' => 'date',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'past_due_since' => 'datetime',
            'read_only_since' => 'datetime',
            'pos_stops_at' => 'datetime',
            'period_amount_minor' => 'integer',
            'monthly_amount_minor' => 'integer',
            'grace_days' => 'integer',
        ];
    }

    /** The plan as a selection: modules, quantities, cycle, bundle. */
    public function plan(): array
    {
        return [
            'modules' => array_values($this->modules ?? []),
            'quantities' => (object) ($this->quantities ?? []),
            'cycle' => $this->cycle,
            'bundle' => $this->bundle,
        ];
    }

    public function hasPeriod(): bool
    {
        return $this->current_period_start !== null;
    }

    /**
     * SUB-03: subscribed during the trial ("Subscribe now"), the trial still
     * running: the first paid period is set and invoiced, and starts when
     * the trial ends.
     */
    public function inSubscribedTrial(CarbonImmutable $at): bool
    {
        return $this->status === self::TRIAL && $this->hasPeriod() && $this->trial_ends_at !== null && $at->lessThan($this->trial_ends_at);
    }

    /** The date the next invoice falls due: the trial end before any period, else the period end. */
    public function nextDueAt(): ?CarbonImmutable
    {
        if ($this->current_period_end !== null) {
            return CarbonImmutable::parse($this->current_period_end->toDateString(), 'UTC');
        }

        return $this->trial_ends_at === null ? null : CarbonImmutable::instance($this->trial_ends_at)->utc();
    }

    public function refusesWrites(): bool
    {
        return in_array($this->status, self::WRITES_REFUSED, true);
    }

    /** Whether the POS may still upload in read-only mode at $at (SUB-05). */
    public function posAllowedAt(CarbonImmutable $at): bool
    {
        if (! $this->refusesWrites()) {
            return true;
        }

        return $this->status === self::READ_ONLY && $this->pos_stops_at !== null && $at->lessThan($this->pos_stops_at);
    }

    public function isCycle(string $cycle): bool
    {
        return $this->cycle === $cycle;
    }

    public static function cycles(): array
    {
        return QuoteRequest::CYCLES;
    }
}
