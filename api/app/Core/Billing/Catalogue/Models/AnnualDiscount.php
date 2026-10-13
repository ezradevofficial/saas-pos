<?php

namespace App\Core\Billing\Catalogue\Models;

use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SUB-01: the annual discount as data ("2 months free" is 10 months
 * charged for 12), effective-dated and never edited, like prices.
 *
 * @property string $id
 * @property int $months_charged
 * @property int $months_in_cycle
 * @property Carbon $effective_from
 * @property Carbon|null $archived_at
 */
class AnnualDiscount extends Model
{
    use HasUuids;

    /** ADR 013: staff writes go through the platform role; public reads use ::on(PlatformDatabase::READ). */
    protected $connection = PlatformDatabase::CONNECTION;

    protected $table = 'catalogue_annual_discounts';

    protected $fillable = ['months_charged', 'months_in_cycle', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['months_charged' => 'integer', 'months_in_cycle' => 'integer', 'effective_from' => 'date', 'archived_at' => 'datetime'];
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public static function current(string $date, string $connection = PlatformDatabase::READ): ?self
    {
        return self::on($connection)->whereNull('archived_at')->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    public function isInEffect(): bool
    {
        return $this->effective_from->toDateString() <= now()->toDateString();
    }
}
