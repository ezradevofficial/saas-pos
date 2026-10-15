<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-12: an employee's minutes of a closed HR attendance period, paid
 * once (`paid_run_id`, set when the run that paid them is posted).
 *
 * @property string $period_id
 * @property string $employee_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property int $regular_minutes
 * @property int $overtime_minutes
 * @property int $night_minutes
 * @property int $holiday_minutes
 * @property int $rest_day_minutes
 * @property int $days_present
 * @property ?string $paid_run_id
 */
class Attendance extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payroll_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date', 'end_date' => 'immutable_date',
            'worked_minutes' => 'integer', 'regular_minutes' => 'integer', 'overtime_minutes' => 'integer',
            'night_minutes' => 'integer', 'holiday_minutes' => 'integer', 'rest_day_minutes' => 'integer', 'days_present' => 'integer',
        ];
    }
}
