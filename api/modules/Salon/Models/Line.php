<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-07, SAL-08: a service of an appointment with its staff member, resource and time. While `holds`, [starts_at,
 * blocked_until) is taken from the staff member and the resource.
 *
 * @property string $appointment_id
 * @property string $service_id
 * @property string $item_id
 * @property string $staff_id
 * @property ?string $resource_id
 * @property int $position
 * @property string $kind
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_until
 * @property int $price_minor
 * @property string $currency
 * @property bool $holds
 * @property ?string $sale_line_id
 */
class Line extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_appointment_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'blocked_until' => 'immutable_datetime',
            'price_minor' => 'integer', 'position' => 'integer', 'holds' => 'boolean',
        ];
    }
}
