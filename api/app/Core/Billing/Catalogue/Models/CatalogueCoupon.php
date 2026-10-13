<?php

namespace App\Core\Billing\Catalogue\Models;

use App\Core\Billing\Catalogue\Pricing\Coupon;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SUB-01: a coupon: a percent or a fixed amount in one currency, for every
 * line or only some modules or bundles, with an optional expiry and number
 * of redemptions. Codes are case-insensitive. Billing (Task 2) counts
 * redemptions.
 *
 * @property string $id
 * @property string $code
 * @property string $kind
 * @property int|null $percent
 * @property int|null $amount
 * @property string|null $currency
 * @property list<string> $applies_to_modules
 * @property list<string> $applies_to_bundles
 * @property Carbon|null $expires_at
 * @property int|null $max_redemptions
 * @property int $redemptions
 * @property Carbon|null $archived_at
 */
class CatalogueCoupon extends Model
{
    use HasUuids;

    /** ADR 013: staff writes go through the platform role; public reads use ::on(PlatformDatabase::READ). */
    protected $connection = PlatformDatabase::CONNECTION;

    protected $table = 'catalogue_coupons';

    protected $fillable = ['code', 'kind', 'percent', 'amount', 'currency', 'applies_to_modules', 'applies_to_bundles', 'expires_at', 'max_redemptions', 'created_by'];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'amount' => 'integer',
            'applies_to_modules' => 'array',
            'applies_to_bundles' => 'array',
            'expires_at' => 'datetime',
            'max_redemptions' => 'integer',
            'redemptions' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** Live, not expired, not used up. */
    public function isUsable(): bool
    {
        return $this->archived_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_redemptions === null || $this->redemptions < $this->max_redemptions);
    }

    public function status(): string
    {
        return match (true) {
            $this->archived_at !== null => 'archived',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            $this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions => 'used_up',
            default => 'active',
        };
    }

    public function toPricing(): Coupon
    {
        return new Coupon(
            code: $this->code,
            kind: $this->kind,
            percent: $this->percent,
            amount: $this->amount,
            currency: $this->currency,
            modules: array_values($this->applies_to_modules ?? []),
            bundles: array_values($this->applies_to_bundles ?? []),
        );
    }
}
