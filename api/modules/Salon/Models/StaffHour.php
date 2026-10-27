<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-04: one weekly work or break span of a staff member at a branch (local time). The list is replaced as a whole
 * and audited as one event by Staff\Hours.
 *
 * @property string $staff_id
 * @property string $branch_id
 * @property int $weekday
 * @property string $kind
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 */
class StaffHour extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_staff_hours';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
