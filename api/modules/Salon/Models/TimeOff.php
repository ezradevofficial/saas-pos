<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-04: a staff member's time off.
 *
 * @property string $staff_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $reason
 * @property ?CarbonImmutable $cancelled_at
 */
class TimeOff extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_time_off';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'time_off';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** The scope of the staff member this time off is for. */
    public function recordScope(): Scope
    {
        return Staff::query()->findOrFail($this->staff_id)->scope();
    }
}
