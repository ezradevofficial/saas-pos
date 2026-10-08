<?php

namespace App\Core\Workflow\Calendar;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday from a country pack (CP-01): every year on a month and
 * day (between the optional effective dates), or on one date. Global and
 * read-only at run time; written by `country-packs:holidays`.
 *
 * @property string $country
 * @property string $key
 * @property ?int $month
 * @property ?int $day
 * @property ?string $date
 * @property ?string $effective_from
 * @property ?string $effective_to
 */
class PublicHoliday extends Model
{
    use HasUuids;

    protected $fillable = ['country', 'key', 'month', 'day', 'date', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
        ];
    }
}
