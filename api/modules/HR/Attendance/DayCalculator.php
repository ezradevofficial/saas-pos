<?php

namespace Modules\HR\Attendance;

use Carbon\CarbonImmutable;

/**
 * HR-25: one timesheet day from data, without the database (unit-tested):
 *
 * - punches pair in order: an `in` opens an interval (a second `in` while
 *   one is open is ignored), an `out` closes it (an `out` with none open is
 *   `missing_in`); an interval left open is `missing_out`;
 * - worked = the intervals' minutes, less the planned shift's unpaid break
 *   when the time worked is longer than the break;
 * - overtime: worked beyond the daily threshold, then regular time beyond
 *   the weekly threshold counting the week's earlier regular minutes
 *   (ISO week); worked = regular + overtime;
 * - night, holiday and rest-day minutes: the parts of the intervals inside
 *   the night window (start and end times, crossing midnight when the end
 *   is not after the start), inside a public holiday's local day, inside a
 *   weekly rest day; each at most the worked minutes (overlays, not
 *   buckets: Payroll pays premiums on them);
 * - late and early leave: against the planned shift, counted in full when
 *   beyond the grace;
 * - status: `present` (or `incomplete` with an unpaired punch) when there
 *   are punches; else `leave` on an approved leave day; else for a planned
 *   shift `holiday` on a public holiday, `absent` once the shift ended;
 *   null (no day) otherwise.
 *
 * All instants are CarbonImmutable; local dates and times are in $timezone.
 */
final class DayCalculator
{
    /**
     * @param  list<array{kind: string, at: CarbonImmutable}>  $punches  sorted by time
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, break: int}|null  $planned
     * @param  array{daily: ?int, weekly: ?int, night_start: ?string, night_end: ?string, late_grace: int, early_grace: int, rest_days: list<string>}|null  $rule
     * @param  callable(string): bool  $isHoliday  a local Y-m-d is a public holiday
     * @return array<string, mixed>|null
     */
    public static function compute(
        string $workDate,
        string $timezone,
        array $punches,
        ?array $planned,
        ?array $rule,
        callable $isHoliday,
        int $weekRegularBefore,
        bool $onLeave,
        CarbonImmutable $now,
    ): ?array {
        $intervals = [];
        $exceptions = [];
        $open = null;
        $firstIn = null;
        $lastOut = null;

        foreach ($punches as $punch) {
            if ($punch['kind'] === 'in') {
                $firstIn ??= $punch['at'];

                if ($open === null) {
                    $open = $punch['at'];
                }

                continue;
            }

            $lastOut = $punch['at'];

            if ($open === null) {
                $exceptions['missing_in'] = true;

                continue;
            }

            if ($punch['at']->greaterThan($open)) {
                $intervals[] = [$open, $punch['at']];
            }

            $open = null;
        }

        if ($open !== null) {
            $exceptions['missing_out'] = true;
        }

        $result = [
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => 0, 'regular_minutes' => 0, 'overtime_minutes' => 0, 'night_minutes' => 0,
            'holiday_minutes' => 0, 'rest_day_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0,
        ];

        if ($punches === []) {
            if ($onLeave) {
                return [...$result, 'status' => 'leave', 'exceptions' => []];
            }

            if ($planned === null) {
                return null;
            }

            if ($isHoliday($workDate)) {
                return [...$result, 'status' => 'holiday', 'exceptions' => []];
            }

            if ($now->greaterThanOrEqualTo($planned['end'])) {
                return [...$result, 'status' => 'absent', 'exceptions' => ['absent']];
            }

            return null;
        }

        $raw = 0;

        foreach ($intervals as [$from, $to]) {
            $raw += self::minutes($from, $to);
        }

        $break = $planned['break'] ?? 0;
        $worked = $raw > $break ? $raw - $break : $raw;

        $daily = $rule['daily'] ?? null;
        $weekly = $rule['weekly'] ?? null;
        $dailyOvertime = $daily === null ? 0 : max(0, $worked - $daily);
        $candidate = $worked - $dailyOvertime;
        $weeklyOvertime = $weekly === null ? 0 : min($candidate, max(0, $weekRegularBefore + $candidate - $weekly));

        $result['worked_minutes'] = $worked;
        $result['regular_minutes'] = $candidate - $weeklyOvertime;
        $result['overtime_minutes'] = $dailyOvertime + $weeklyOvertime;

        if ($rule !== null && $rule['night_start'] !== null && $rule['night_end'] !== null) {
            $night = 0;
            $base = CarbonImmutable::parse($workDate, $timezone);

            foreach ([-1, 0, 1] as $offset) {
                $day = $base->addDays($offset)->toDateString();
                $start = CarbonImmutable::parse($day.' '.$rule['night_start'], $timezone);
                $end = CarbonImmutable::parse($day.' '.$rule['night_end'], $timezone);

                if ($end->lessThanOrEqualTo($start)) {
                    $end = $end->addDay();
                }

                $night += self::overlap($intervals, $start, $end);
            }

            $result['night_minutes'] = min($worked, $night);
        }

        $holiday = 0;
        $rest = 0;

        foreach (self::localDays($intervals, $timezone) as $day) {
            $start = CarbonImmutable::parse($day, $timezone);
            $end = $start->addDay();

            if ($isHoliday($day)) {
                $holiday += self::overlap($intervals, $start, $end);
            }

            if ($rule !== null && in_array(strtolower($start->format('D')), $rule['rest_days'], true)) {
                $rest += self::overlap($intervals, $start, $end);
            }
        }

        $result['holiday_minutes'] = min($worked, $holiday);
        $result['rest_day_minutes'] = min($worked, $rest);

        if ($planned !== null) {
            if ($firstIn !== null && $firstIn->greaterThan($planned['start'])) {
                $late = self::minutes($planned['start'], $firstIn);
                $result['late_minutes'] = $late > ($rule['late_grace'] ?? 0) ? $late : 0;
            }

            if ($lastOut !== null && ! isset($exceptions['missing_out']) && $lastOut->lessThan($planned['end'])) {
                $early = self::minutes($lastOut, $planned['end']);
                $result['early_leave_minutes'] = $early > ($rule['early_grace'] ?? 0) ? $early : 0;
            }
        } else {
            $exceptions['unplanned'] = true;
        }

        if ($result['late_minutes'] > 0) {
            $exceptions['late'] = true;
        }

        if ($result['early_leave_minutes'] > 0) {
            $exceptions['early_leave'] = true;
        }

        if ($rule === null && $worked > 0) {
            $exceptions['no_time_rule'] = true;
        }

        $order = ['late', 'early_leave', 'missing_in', 'missing_out', 'absent', 'unplanned', 'no_time_rule'];

        return [
            ...$result,
            'status' => isset($exceptions['missing_out']) || isset($exceptions['missing_in']) ? 'incomplete' : 'present',
            'exceptions' => array_values(array_filter($order, fn (string $key) => isset($exceptions[$key]))),
        ];
    }

    private static function minutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60);
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $intervals */
    private static function overlap(array $intervals, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $total = 0;

        foreach ($intervals as [$from, $to]) {
            $s = max($from->getTimestamp(), $start->getTimestamp());
            $e = min($to->getTimestamp(), $end->getTimestamp());
            $total += $e > $s ? intdiv($e - $s, 60) : 0;
        }

        return $total;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return list<string> local dates the intervals touch
     */
    private static function localDays(array $intervals, string $timezone): array
    {
        $days = [];

        foreach ($intervals as [$from, $to]) {
            for ($day = $from->setTimezone($timezone)->startOfDay(); $day->lessThan($to); $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        return array_keys($days);
    }
}
