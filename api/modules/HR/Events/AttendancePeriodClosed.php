<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HR-26, HR-33: an attendance period closed (after commit), with the
 * approved totals per employee in minutes. `worked = regular + overtime`;
 * night, holiday and rest-day minutes are parts of `worked` that Payroll
 * pays a premium on at its own rates.
 */
class AttendancePeriodClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{employee_id: string, days_present: int, days_absent: int, days_leave: int, worked_minutes: int, regular_minutes: int, overtime_minutes: int, night_minutes: int, holiday_minutes: int, rest_day_minutes: int, late_minutes: int, early_leave_minutes: int}>  $employees
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $periodId,
        public readonly string $companyId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $timezone,
        public readonly ?string $closedBy,
        public readonly array $employees,
    ) {}
}
