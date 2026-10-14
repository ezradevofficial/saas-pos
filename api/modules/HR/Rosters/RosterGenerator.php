<?php

namespace Modules\HR\Rosters;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\Roster;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\Rotation;
use Modules\HR\Models\Shift;
use Modules\HR\Support\Errors;

/**
 * HR-17: a roster's planned shifts over a date range, idempotently. Day d
 * of member m works the rotation's day ((d − anchor) + offset) mod cycle;
 * a null day is rest. An existing planned shift of the employee on that
 * date (generated, manual or swapped) is kept; approved leave days are
 * skipped; nothing outside the member's period or the employment.
 */
class RosterGenerator
{
    public const MAX_DAYS = 92;

    public function __construct(private readonly Schedules $schedules) {}

    /** @return array{created: int, kept: int, leave: int} */
    public function generate(Roster $roster, CarbonImmutable $from, CarbonImmutable $to, User $by): array
    {
        if ($to->lessThan($from) || $from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw Errors::make(422, 'range_too_long', ['days' => self::MAX_DAYS], 'to');
        }

        $rotation = Rotation::query()->findOrFail($roster->rotation_id);
        $shifts = Shift::query()->whereKey(array_values(array_unique(array_filter($rotation->days))))->get()->keyBy('id');
        $counts = ['created' => 0, 'kept' => 0, 'leave' => 0];

        foreach ($roster->members()->get() as $member) {
            $employee = Employee::query()->find($member->employee_id);

            if ($employee === null) {
                continue;
            }

            $zone = $this->schedules->timezoneOf($employee);
            $leave = $this->leaveDates($employee->id, $from, $to);

            Errors::transaction(function () use ($roster, $rotation, $shifts, $member, $employee, $zone, $leave, $from, $to, $by, &$counts) {
                $existing = RosterShift::query()->where('employee_id', $employee->id)->where('status', RosterShift::PLANNED)
                    ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')
                    ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->flip()->all();

                for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                    $date = $day->toDateString();

                    if (! $this->employed($employee, $member, $date)) {
                        continue;
                    }

                    $index = ((int) $roster->anchor_date->diffInDays($day, false) + $member->offset_days) % $rotation->cycle_days;
                    $shiftId = $rotation->days[($index + $rotation->cycle_days) % $rotation->cycle_days] ?? null;

                    if ($shiftId === null || ! $shifts->has($shiftId)) {
                        continue;
                    }

                    if (isset($existing[$date])) {
                        $counts['kept']++;

                        continue;
                    }

                    if (isset($leave[$date])) {
                        $counts['leave']++;

                        continue;
                    }

                    [$start, $end] = $this->schedules->window($shifts[$shiftId], $date, $zone);
                    RosterShift::create([
                        'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id,
                        'date' => $date, 'shift_id' => $shiftId, 'start_at' => $start, 'end_at' => $end, 'break_minutes' => $shifts[$shiftId]->break_minutes,
                        'source' => 'generated', 'roster_id' => $roster->id, 'status' => RosterShift::PLANNED, 'created_by' => $by->id,
                    ]);
                    $counts['created']++;
                }
            });
        }

        return $counts;
    }

    private function employed(Employee $employee, $member, string $date): bool
    {
        return $date >= $employee->hire_date->toDateString()
            && ($employee->termination_date === null || $date <= $employee->termination_date->toDateString())
            && ($member->from_date === null || $date >= $member->from_date->toDateString())
            && ($member->to_date === null || $date <= $member->to_date->toDateString());
    }

    /** @return array<string, true> approved leave dates of the employee in the range */
    private function leaveDates(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        $requests = LeaveRequest::query()->where('employee_id', $employeeId)->where('status', LeaveRequest::APPROVED)
            ->where('start_date', '<=', $to->toDateString())->where('end_date', '>=', $from->toDateString())->get(['dates']);

        foreach ($requests as $request) {
            foreach ($request->dates as $day) {
                $dates[$day['date']] = true;
            }
        }

        return $dates;
    }
}
