<?php

namespace App\Core\Billing\Catalogue\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SUB-01: the annual discount as data ("2 months free" is 10 months
 * charged for 12), effective-dated and never edited, like prices.
 *
 * @property string $id
 * @property int $months_charged
 * @property int $months_in_cycle
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $archived_at
 */
class AnnualDiscount extends Model
{
    use HasUuids;

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

    public static function current(string $date): ?self
    {
        return self::live()->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    public function isInEffect(): bool
    {
        return $this->effective_from->toDateString() <= now()->toDateString();
    }
}
