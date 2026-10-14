<?php

namespace Modules\HR\Rosters;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Shift;

/**
 * HR-17: where a planned shift falls in time: the shift's start and end
 * times on the date, in the employee's time zone (its branch's, else its
 * company's), as UTC instants; an end at or before the start is the next
 * day (a night shift).
 */
class Schedules
{
    /** @var array<string, string> */
    private array $zones = [];

    public function timezoneOf(Employee $employee): string
    {
        $key = $employee->company_id.'|'.$employee->branch_id;

        return $this->zones[$key] ??= ($employee->branch_id === null ? null : Branch::query()->whereKey($employee->branch_id)->value('timezone'))
            ?: (Company::query()->whereKey($employee->company_id)->value('timezone') ?: 'UTC');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} start and end, UTC */
    public function window(Shift $shift, string $date, string $timezone): array
    {
        $start = CarbonImmutable::parse($date.' '.substr((string) $shift->start_time, 0, 5), $timezone);
        $end = CarbonImmutable::parse($date.' '.substr((string) $shift->end_time, 0, 5), $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }
}
