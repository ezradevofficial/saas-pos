<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-11: a day of unpaid leave from HR's LeaveApproved (cancelled by
 * LeaveCancelled).
 *
 * @property string $employee_id
 * @property CarbonImmutable $date
 * @property string $fraction
 * @property ?CarbonImmutable $cancelled_at
 * @property string $kind unpaid (deducted), pay_unconfirmed (blocks the run), half_pay or reduced_pay (CP-01)
 * @property ?string $pay_share the share of pay kept on a reduced_pay day, an exact fraction ("2/3")
 */
class LeaveDay extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payroll_leave_days';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'cancelled_at' => 'immutable_datetime'];
    }
}
