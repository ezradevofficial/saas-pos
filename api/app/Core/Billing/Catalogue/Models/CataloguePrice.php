<?php

namespace App\Core\Billing\Catalogue\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SUB-01: the monthly price of a module or bundle in one country and its
 * currency, in minor units, from a date. Versioned: a row is never edited
 * (a database trigger refuses it); a new price is a new row with a later
 * date. A row not yet in effect may be archived. Subscriptions (Task 2)
 * keep the id of the row they were sold at.
 *
 * @property string $id
 * @property string $item_type
 * @property string $item_key
 * @property string $country
 * @property string $currency
 * @property int $amount
 * @property int|null $minimum_amount
 * @property int|null $extra_outlet_amount
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $archived_at
 */
class CataloguePrice extends Model
{
    use HasUuids;

    public const MODULE = 'module';

    public const BUNDLE = 'bundle';

    protected $table = 'catalogue_prices';

    protected $fillable = ['item_type', 'item_key', 'country', 'currency', 'amount', 'minimum_amount', 'extra_outlet_amount', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'minimum_amount' => 'integer',
            'extra_outlet_amount' => 'integer',
            'effective_from' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** The row in force for each item on $date (the latest effective date not after it). */
    public function scopeCurrent(Builder $query, string $country, string $date): void
    {
        $query->live()
            ->where('country', $country)
            ->where('effective_from', '<=', $date)
            ->whereRaw('effective_from = (select max(p2.effective_from) from catalogue_prices p2 where p2.item_type = catalogue_prices.item_type and p2.item_key = catalogue_prices.item_key and p2.country = catalogue_prices.country and p2.archived_at is null and p2.effective_from <= ?)', [$date]);
    }

    public function isInEffect(): bool
    {
        return $this->effective_from->toDateString() <= now()->toDateString();
    }
}
