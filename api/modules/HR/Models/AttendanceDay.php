<?php

namespace Modules\HR\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-25: one timesheet day of an employee, computed from punches, the
 * planned shift, the time rules, holidays and leave. Approvals are audited
 * by Timesheets.
 *
 * @property CarbonImmutable $work_date
 * @property ?CarbonImmutable $approved_at
 */
class AttendanceDay extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['present', 'absent', 'leave', 'holiday', 'incomplete'];

    public const MINUTES = ['worked', 'regular', 'overtime', 'night', 'holiday', 'rest_day', 'late', 'early_leave'];

    protected $table = 'hr_attendance_days';

    protected $guarded = [];

    protected function casts(): array
    {
        $casts = ['work_date' => 'immutable_date', 'first_in' => 'immutable_datetime', 'last_out' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'exceptions' => 'array'];

        foreach (self::MINUTES as $minutes) {
            $casts["{$minutes}_minutes"] = 'integer';
        }

        return $casts;
    }

    public function scope(): Scope
    {
        return Employee::placeScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class);
    }
}
