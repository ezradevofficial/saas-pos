<?php

namespace App\Core\Limits\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * LIM-01: a tenant's active count of one unit on one UTC day, with the
 * plan's allowance that day (null: unlimited). Kept as history, never
 * deleted (the runtime role has no DELETE); LIM-05 bills from it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property CarbonImmutable $day
 * @property string $unit
 * @property int $count
 * @property ?int $allowed
 * @property CarbonImmutable $metered_at
 */
class UsageMeter extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['day', 'unit', 'count', 'allowed', 'metered_at'];

    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'count' => 'integer',
            'allowed' => 'integer',
            'metered_at' => 'immutable_datetime',
        ];
    }
}
