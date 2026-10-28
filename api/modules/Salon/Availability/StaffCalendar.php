<?php

namespace Modules\Salon\Availability;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\HR\Contracts\StaffAvailability;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\StaffHour;
use Modules\Salon\Models\TimeOff;
use Modules\Salon\Support\Places;

/**
 * SAL-04: when each staff member can be booked at a branch, for a window of time.
 *
 * - `work`: spans they are on duty. Without HR (or for staff not linked to an employee) the weekly spans of the module,
 *   in the branch's local time; with HR active and an employee linked, the planned roster shifts of HR (HR is the source).
 * - `off`: spans they cannot be booked inside the work spans: the weekly break spans of the module, their time off and,
 *   for a linked employee with HR active, every local day of approved HR leave.
 *
 * All spans are UTC instants as [start, end] pairs of CarbonImmutable.
 */
class StaffCalendar
{
    public function __construct(
        private readonly Places $places,
        private readonly ModuleRegistry $modules,
        private readonly StaffAvailability $hr,
    ) {}

    /**
     * @param  Collection<int, Staff>  $staff
     * @return array<string, array{work: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, off: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}> by staff id
     */
    public function spans(Collection $staff, Branch $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $zone = $this->places->zone($branch);
        $hrOn = $this->modules->isActive('hr');
        $linked = $hrOn ? $staff->filter(fn (Staff $s) => $s->employee_id !== null)->pluck('employee_id', 'id')->all() : [];
        $shifts = $linked === [] ? [] : collect($this->hr->shifts(array_values($linked), $from, $to))->groupBy('employee_id')->all();
        $leave = $linked === [] ? [] : collect($this->hr->leaveDays(array_values($linked), $from->setTimezone($zone)->toDateString(), $to->setTimezone($zone)->toDateString()))->groupBy('employee_id')->all();
        $hours = StaffHour::query()->whereIn('staff_id', $staff->modelKeys())->where('branch_id', $branch->id)->orderBy('weekday')->orderBy('starts_at')->get()->groupBy('staff_id');
        $timeOff = TimeOff::query()->whereIn('staff_id', $staff->modelKeys())->whereNull('cancelled_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get()->groupBy('staff_id');

        $out = [];

        foreach ($staff as $member) {
            $work = [];
            $off = [];
            $breaks = [];
            $leaves = [];
            $rows = $hours->get($member->id, collect());
            $employee = $linked[$member->id] ?? null;

            for ($day = $from->setTimezone($zone)->startOfDay(); $day->lessThan($to); $day = $day->addDay()->startOfDay()) {
                $weekday = (int) $day->format('N');

                foreach ($rows->where('weekday', $weekday) as $row) {
                    $span = [self::at($day, (string) $row->starts_at), self::at($day, (string) $row->ends_at)];

                    if ($row->kind === 'break') {
                        $off[] = $span;
                        $breaks[] = $span;
                    } elseif ($employee === null) {
                        $work[] = $span;
                    }
                }
            }

            if ($employee !== null) {
                foreach ($shifts[$employee] ?? [] as $shift) {
                    if ($shift['branch_id'] === null || $shift['branch_id'] === $branch->id) {
                        $work[] = [CarbonImmutable::parse($shift['start_at'])->utc(), CarbonImmutable::parse($shift['end_at'])->utc()];
                    }
                }

                foreach ($leave[$employee] ?? [] as $row) {
                    $start = CarbonImmutable::parse($row['date'].' 00:00:00', $zone);
                    $off[] = $leaves[] = [$start->utc(), $start->addDay()->utc()];
                }
            }

            foreach ($timeOff->get($member->id, collect()) as $entry) {
                $off[] = $leaves[] = [$entry->starts_at->utc(), $entry->ends_at->utc()];
            }

            $out[$member->id] = ['work' => $work, 'off' => $off, 'breaks' => $breaks, 'time_off' => $leaves];
        }

        return $out;
    }

    /** Whether [$start, $end) lies inside one work span and touches no off span. */
    public static function free(array $spans, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return self::onDuty($spans, $start, $end) && ! self::off($spans, $start, $end);
    }

    public static function onDuty(array $spans, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($spans['work'] as [$from, $to]) {
            if ($start->greaterThanOrEqualTo($from) && $end->lessThanOrEqualTo($to)) {
                return true;
            }
        }

        return false;
    }

    public static function off(array $spans, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($spans['off'] as [$from, $to]) {
            if ($start->lessThan($to) && $end->greaterThan($from)) {
                return true;
            }
        }

        return false;
    }

    /** The minutes of work a staff member has inside [$from, $to), breaks and time off taken out. */
    public static function availableMinutes(array $spans, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $minutes = 0;

        foreach ($spans['work'] as [$start, $end]) {
            $start = $start->max($from);
            $end = $end->min($to);

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $total = (int) $start->diffInMinutes($end);

            foreach ($spans['off'] as [$offStart, $offEnd]) {
                $a = $offStart->max($start);
                $b = $offEnd->min($end);

                if ($b->greaterThan($a)) {
                    $total -= (int) $a->diffInMinutes($b);
                }
            }

            $minutes += max(0, $total);
        }

        return $minutes;
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' '.substr($time, 0, 8), $day->getTimezone())->utc();
    }
}
