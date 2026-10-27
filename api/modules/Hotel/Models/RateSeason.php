<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HOT-07: a dated season of a rate plan; the highest priority covering a night sets its rate.
 *
 * @property string $rate_plan_id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $weekday_rate_minor
 * @property ?int $weekend_rate_minor
 * @property int $priority
 */
class RateSeason extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hotel_rate_seasons';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'weekday_rate_minor' => 'integer',
            'weekend_rate_minor' => 'integer',
            'priority' => 'integer',
        ];
    }
}
