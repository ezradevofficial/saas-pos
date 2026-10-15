<?php

namespace Modules\HR\Leave;

use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveType;
use Modules\HR\Models\RosterMember;
use Modules\HR\Models\RosterShift;
use Modules\HR\Rosters\RosterGenerator;

/**
 * HR-14: the days a leave range counts.
 *
 * - `calendar_days`: every day.
 * - `working_days`, rostered staff (owner decision C): only the days they
 *   are rostered to work: a day with a planned shift; a day already
 *   planned without one (rest, or a shift cancelled) does not count; a day
 *   not planned yet counts when their roster's rotation has a shift then.
 * - `working_days`, everyone else (and days outside a roster membership):
 *   the company's working days (core business hours, closed on its
 *   country's public holidays, CP-01).
 *
 * A half day at either end counts 0.5 (a one-day range with both halves 0.5).
 */
class LeaveDays
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** @return list<array{date: string, fraction: string}> */
    public function dates(LeaveType $type, Employee $employee, string $start, string $end, bool $startHalf, bool $endHalf): array
    {
        $spec = $this->calendar->forCompany($employee->company_id);
        $from = CarbonImmutable::parse($start, $spec->timezone)->setTime(12, 0);
        $to = CarbonImmutable::parse($end, $spec->timezone)->setTime(12, 0);
        $rostered = $type->count_basis === 'working_days' ? $this->rostered($employee, $start, $end) : null;
        $dates = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($type->count_basis === 'working_days') {
                $works = $rostered === null ? null : $rostered($day);

                if ($works === false || ($works === null && $this->calendar->openIntervals($day, $spec) === [])) {
                    continue;
                }
            }

            $half = ($day->isSameDay($from) && $startHalf) || ($day->isSameDay($to) && $endHalf);
            $dates[] = ['date' => $day->toDateString(), 'fraction' => $half ? '0.50' : '1.00'];
        }

        return $dates;
    }

    /**
     * Whether the employee works a day by their roster (null: not on a
     * roster that day), or null when they are on no roster in the range.
     *
     * @return (callable(CarbonImmutable): ?bool)|null
     */
    private function rostered(Employee $employee, string $start, string $end): ?callable
    {
        $members = RosterMember::query()->with('roster.rotation')->where('employee_id', $employee->id)
            ->whereHas('roster', fn ($q) => $q->whereNull('archived_at'))
            ->where(fn ($q) => $q->whereNull('from_date')->orWhere('from_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $start))
            ->get();

        if ($members->isEmpty()) {
            return null;
        }

        $shifts = RosterShift::query()->where('employee_id', $employee->id)->whereBetween('date', [$start, $end])->get(['date', 'status'])
            ->groupBy(fn (RosterShift $s) => $s->date->toDateString());

        return function (CarbonImmutable $day) use ($members, $shifts): ?bool {
            $date = $day->toDateString();
            $member = $members->first(fn (RosterMember $m) => ($m->from_date === null || $m->from_date->toDateString() <= $date) && ($m->to_date === null || $m->to_date->toDateString() >= $date));

            if ($member === null) {
                return null;
            }

            if ($shifts->has($date)) {
                return $shifts[$date]->contains(fn (RosterShift $s) => $s->status === RosterShift::PLANNED);
            }

            return RosterGenerator::shiftOn($member->roster, $member->roster->rotation, $member->offset_days, $day) !== null;
        };
    }

    /** @param list<array{date: string, fraction: string}> $dates */
    public static function total(array $dates): string
    {
        $cents = array_sum(array_map(fn (array $d) => (int) round(((float) $d['fraction']) * 100), $dates));

        return number_format($cents / 100, 2, '.', '');
    }
}
