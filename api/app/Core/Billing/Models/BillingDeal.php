<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ADM-02: a custom Enterprise deal, a negotiated price for this tenant
 * that overrides catalogue lines over its effective period. Drafted by
 * sales or finance, approved by finance (never the drafter); terms never
 * change once written (trigger), an approved deal can only be ended.
 *
 * @property string $id
 * @property string $status
 * @property string $country
 * @property string $currency
 * @property string|null $bundle
 * @property list<array{type: string, key: string, amount: int, extra_outlet_amount?: ?int, minimum_amount?: ?int}> $lines
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $reason
 * @property string $created_by_staff
 * @property string $created_by_name
 * @property string|null $decided_by_staff
 * @property string|null $decided_by_name
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $ended_at
 */
class BillingDeal extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ENDED = 'ended';

    protected $fillable = ['status', 'country', 'currency', 'bundle', 'lines', 'effective_from', 'effective_to', 'reason', 'created_by_staff', 'created_by_name',
        'decided_by_staff', 'decided_by_name', 'decided_at', 'decision_note', 'ended_by_staff', 'ended_at'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'decided_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Approved (or ended) deals whose period covers $on. */
    public function scopeInForceOn(Builder $query, CarbonImmutable $on): Builder
    {
        $date = $on->toDateString();

        return $query->whereIn('status', [self::APPROVED, self::ENDED])
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
