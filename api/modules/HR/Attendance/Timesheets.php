<?php

namespace Modules\HR\Attendance;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\AttendancePeriod;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\Punch;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\TimeRule;
use Modules\HR\Rosters\Schedules;
use Modules\HR\Support\Errors;

/**
 * HR-25: timesheet days, computed by DayCalculator from the employee's
 * punches, planned shift, the company's time rules in force, its country's
 * public holidays (CP-01, through the core calendar) and approved leave.
 *
 * A punch belongs to the work date of the planned shift whose window
 * (6 hours either side) holds it; else an `out` belongs to the day of the
 * `in` it closes (within 16 hours); else to its local date. Approved days
 * and days of closed periods are never recomputed. Recomputing a day also
 * recomputes the later days of its ISO week (weekly overtime); an upload
 * of many punches recomputes each touched day and week once (touchMany).
 */
class Timesheets
{
    private const SHIFT_MARGIN_HOURS = 6;

    public const OVERNIGHT_HOURS = 16;

    public function __construct(
        private readonly Schedules $schedules,
        private readonly BusinessCalendar $calendar,
        private readonly Auditor $auditor,
    ) {}

    /**
     * The work date $punch belongs to (HR-25): an `out` closing an `in`
     * (the latest punch within 16 hours is an `in`) belongs to that in's
     * day; else the planned shift whose window (6 hours either side) holds
     * the punch, nearest start first; else its local date.
     */
    public function workDateOf(Punch $punch, ?Employee $employee = null): string
    {
        $employee ??= Employee::query()->findOrFail($punch->employee_id);
        $zone = $this->schedules->timezoneOf($employee);
        $local = $punch->occurred_at->setTimezone($zone);
        $margin = self::SHIFT_MARGIN_HOURS * 3600;

        if ($punch->kind === Punch::OUT) {
            $previous = Punch::query()->where('employee_id', $employee->id)->whereNull('voided_at')
                ->when($punch->exists, fn ($q) => $q->whereKeyNot($punch->id))
                ->where('occurred_at', '<=', $punch->occurred_at)->where('occurred_at', '>=', $punch->occurred_at->subHours(self::OVERNIGHT_HOURS))
                ->orderByDesc('occurred_at')->orderByDesc('id')->first();

            if ($previous !== null && $previous->kind === Punch::IN) {
                return $previous->work_date?->toDateString() ?? $this->workDateOf($previous, $employee);
            }
        }

        $planned = RosterShift::query()->where('employee_id', $employee->id)->where('status', RosterShift::PLANNED)
            ->whereBetween('date', [$local->subDay()->toDateString(), $local->addDay()->toDateString()])->get();
        $best = null;

        foreach ($planned as $shift) {
            $at = $punch->occurred_at->getTimestamp();

            if ($at >= $shift->start_at->getTimestamp() - $margin && $at <= $shift->end_at->getTimestamp() + $margin) {
                $distance = abs($at - $shift->start_at->getTimestamp());

                if ($best === null || $distance < $best[0]) {
                    $best = [$distance, $shift->date->toDateString()];
                }
            }
        }

        return $best !== null ? $best[1] : $local->toDateString();
    }

    /** Recompute the day of $employeeId on $date and the later days of its week (unless approved or closed). */
    public function touch(string $employeeId, string $date): void
    {
        $this->touchMany([$employeeId => [$date => true]]);
    }

    /**
     * HR-25: recompute many days at once (a punch upload): each employee's
     * touched days and the later days of each touched ISO week (weekly
     * overtime), every day once, in date order.
     *
     * @param  array<string, array<string, true>>  $days  employee id => set of work dates
     */
    public function touchMany(array $days): void
    {
        foreach ($days as $employeeId => $dates) {
            $employee = Employee::query()->find($employeeId);

            if ($employee === null || $dates === []) {
                continue;
            }

            $weeks = [];

            foreach (array_keys($dates) as $date) {
                $weeks[CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString()][] = $date;
            }

            ksort($weeks);

            foreach ($weeks as $monday => $touched) {
                sort($touched);
                $later = AttendanceDay::query()->where('employee_id', $employeeId)
                    ->where('work_date', '>', $touched[0])->where('work_date', '<=', CarbonImmutable::parse($monday)->endOfWeek(CarbonInterface::SUNDAY)->toDateString())
                    ->pluck('work_date')->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->all();
                $all = array_values(array_unique([...$touched, ...$later]));
                sort($all);

                foreach ($all as $date) {
                    $this->recompute($employee, $date);
                }
            }
        }
    }

    /** Recompute one day; returns it (null when the day has nothing to show). */
    public function recompute(Employee $employee, string $date): ?AttendanceDay
    {
        return Errors::transaction(function () use ($employee, $date) {
            $existing = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->lockForUpdate()->first();

            if ($existing !== null && ($existing->approved_at !== null || $existing->period_id !== null)) {
                return $existing;
            }

            // HR-26: the covering period is locked, so a close cannot slip in between.
            if ($this->coveringPeriod($employee->company_id, $date, lock: true)?->status === AttendancePeriod::CLOSED) {
                return $existing;
            }

            $values = $this->compute($employee, $date);

            if ($values === null) {
                // Derived data: a day with nothing left to show goes.
                $existing?->delete();

                return null;
            }

            $day = $existing ?? new AttendanceDay(['employee_id' => $employee->id, 'work_date' => $date]);
            $day->fill([...$values, 'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id])->save();

            return $day->refresh();
        });
    }

    /** @return array<string, mixed>|null the day's values */
    public function compute(Employee $employee, string $date): ?array
    {
        $zone = $this->schedules->timezoneOf($employee);
        $local = CarbonImmutable::parse($date, $zone);
        $planned = RosterShift::query()->where('employee_id', $employee->id)->where('status', RosterShift::PLANNED)->where('date', $date)->first();

        // HR-22: a punch's work date is fixed when it is recorded (older punches without one are placed now).
        $punches = Punch::query()->where('employee_id', $employee->id)->whereNull('voided_at')
            ->where(fn ($q) => $q->where('work_date', $date)
                ->orWhere(fn ($w) => $w->whereNull('work_date')->whereBetween('occurred_at', [$local->subHours(30)->utc(), $local->addHours(54)->utc()])))
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->filter(fn (Punch $punch) => ($punch->work_date?->toDateString() ?? $this->workDateOf($punch, $employee)) === $date)
            ->map(fn (Punch $punch) => ['kind' => $punch->kind, 'at' => $punch->occurred_at])
            ->values()->all();

        $rule = $this->ruleOn($employee->company_id, $date);
        $country = Company::query()->whereKey($employee->company_id)->value('country');
        $weekStart = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $weekBefore = (int) AttendanceDay::query()->where('employee_id', $employee->id)
            ->where('work_date', '>=', $weekStart)->where('work_date', '<', $date)->sum('regular_minutes');

        $values = DayCalculator::compute(
            $date,
            $zone,
            $punches,
            $planned === null ? null : ['start' => $planned->start_at, 'end' => $planned->end_at, 'break' => $planned->break_minutes],
            $rule === null ? null : [
                'daily' => $rule->daily_overtime_after_minutes,
                'weekly' => $rule->weekly_overtime_after_minutes,
                'night_start' => $rule->night_start === null ? null : substr((string) $rule->night_start, 0, 5),
                'night_end' => $rule->night_end === null ? null : substr((string) $rule->night_end, 0, 5),
                'late_grace' => $rule->late_grace_minutes,
                'early_grace' => $rule->early_leave_grace_minutes,
                'rest_days' => $rule->rest_days ?? [],
            ],
            fn (string $day) => $this->calendar->isHoliday(CarbonImmutable::parse($day), $country),
            $weekBefore,
            $this->onLeave($employee->id, $date),
            CarbonImmutable::now(),
        );

        if ($values === null) {
            return null;
        }

        return [...$values, 'roster_shift_id' => $planned?->id];
    }

    /**
     * Recompute every day of a company's employees in a range that has a
     * planned shift or punches (and has not been approved).
     */
    public function recomputeRange(string $companyId, string $from, string $to): int
    {
        $pairs = $this->daysWithActivity($companyId, $from, $to);
        $count = 0;

        foreach ($pairs as $employeeId => $dates) {
            $employee = Employee::query()->find($employeeId);
            // In date order: weekly overtime reads the earlier days of the week.
            ksort($dates);

            foreach (array_keys($dates) as $date) {
                $count += $employee !== null && $this->recompute($employee, $date) !== null ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * Employee id => set of dates with a planned shift that started, or a
     * punch, in the range.
     *
     * @return array<string, array<string, true>>
     */
    public function daysWithActivity(string $companyId, string $from, string $to): array
    {
        $pairs = [];
        $shifts = RosterShift::query()->where('company_id', $companyId)->where('status', RosterShift::PLANNED)
            ->whereBetween('date', [$from, $to])->where('start_at', '<=', CarbonImmutable::now())->get(['employee_id', 'date']);

        foreach ($shifts as $shift) {
            $pairs[$shift->employee_id][$shift->date->toDateString()] = true;
        }

        $punches = Punch::query()->where('company_id', $companyId)->whereNull('voided_at')
            ->whereBetween('occurred_at', [CarbonImmutable::parse($from)->subDay(), CarbonImmutable::parse($to)->addDays(2)])->get();
        $employees = Employee::query()->whereKey($punches->pluck('employee_id')->unique()->all())->get()->keyBy('id');

        foreach ($punches as $punch) {
            $date = $punch->work_date?->toDateString() ?? $this->workDateOf($punch, $employees[$punch->employee_id]);

            if ($date >= $from && $date <= $to) {
                $pairs[$punch->employee_id][$date] = true;
            }
        }

        return $pairs;
    }

    /**
     * Approve days (frozen until reopened). Days of closed periods are
     * refused. Each open day is recomputed first (with the later days of
     * its week), so an approval never freezes stale minutes (decision 29);
     * a day left with nothing to show is gone and not approved.
     *
     * @param  Collection<int, AttendanceDay>  $days
     */
    public function approve(Collection $days, User $by): int
    {
        $touched = [];

        foreach ($days as $day) {
            if ($day->approved_at === null && $day->period_id === null) {
                $touched[$day->employee_id][$day->work_date->toDateString()] = true;
            }
        }

        $this->touchMany($touched);

        return Errors::transaction(function () use ($days, $by) {
            $count = 0;

            foreach ($days as $day) {
                $day = AttendanceDay::query()->whereKey($day->id)->lockForUpdate()->first();

                if ($day === null) {
                    continue;
                }

                if ($day->period_id !== null || $this->coveringPeriod($day->company_id, $day->work_date->toDateString(), lock: true)?->status === AttendancePeriod::CLOSED) {
                    throw Errors::make(409, 'period_closed');
                }

                if ($day->approved_at === null) {
                    $day->fill(['approved_at' => CarbonImmutable::now(), 'approved_by' => $by->id])->save();
                    $this->auditor->record('hr.attendance_day.approve', $day, null, ['work_date' => $day->work_date->toDateString(), 'employee_id' => $day->employee_id]);
                    $count++;
                }
            }

            return $count;
        });
    }

    /** @param Collection<int, AttendanceDay> $days */
    public function reopen(Collection $days, User $by): int
    {
        $reopened = Errors::transaction(function () use ($days) {
            $done = [];

            foreach ($days as $day) {
                $day = AttendanceDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();

                if ($day->period_id !== null || $this->closedPeriod($day->company_id, $day->work_date->toDateString()) !== null) {
                    throw Errors::make(409, 'period_closed');
                }

                if ($day->approved_at !== null) {
                    $day->fill(['approved_at' => null, 'approved_by' => null])->save();
                    $this->auditor->record('hr.attendance_day.reopen', $day, null, ['work_date' => $day->work_date->toDateString(), 'employee_id' => $day->employee_id]);
                    $done[] = [$day->employee_id, $day->work_date->toDateString()];
                }
            }

            return $done;
        });

        foreach ($reopened as [$employeeId, $date]) {
            $this->touch($employeeId, $date);
        }

        return count($reopened);
    }

    public function ruleOn(string $companyId, string $date): ?TimeRule
    {
        return TimeRule::query()->where('company_id', $companyId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->first();
    }

    /** The period covering $date (locked for the transaction when $lock). */
    public function coveringPeriod(string $companyId, string $date, bool $lock = false): ?AttendancePeriod
    {
        return AttendancePeriod::query()->where('company_id', $companyId)->where('start_date', '<=', $date)->where('end_date', '>=', $date)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function closedPeriod(string $companyId, string $date): ?AttendancePeriod
    {
        return AttendancePeriod::query()->where('company_id', $companyId)->where('status', AttendancePeriod::CLOSED)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    private function onLeave(string $employeeId, string $date): bool
    {
        return LeaveRequest::query()->where('employee_id', $employeeId)->where('status', LeaveRequest::APPROVED)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)
            ->whereRaw('dates @> ?::jsonb', [json_encode([['date' => $date]])])
            ->exists();
    }
}
