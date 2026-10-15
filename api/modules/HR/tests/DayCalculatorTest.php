<?php

namespace Modules\HR\Tests;

use Carbon\CarbonImmutable;
use Modules\HR\Attendance\DayCalculator;
use PHPUnit\Framework\TestCase;

// HR-20, HR-21, HR-25: overtime (daily, then weekly), night, holiday and
// rest-day minutes, lateness and absence computed from data alone.
class DayCalculatorTest extends TestCase
{
    private const TZ = 'Africa/Nairobi';

    /** Daily overtime after 8 h, weekly after 45 h, nights 22:00-06:00, 10 minutes' grace, Sunday rest. */
    private const RULE = ['daily' => 480, 'weekly' => 2700, 'night_start' => '22:00', 'night_end' => '06:00', 'late_grace' => 10, 'early_grace' => 10, 'rest_days' => ['sun']];

    public function test_a_day_shift_with_a_break_late_beyond_grace_and_daily_overtime(): void
    {
        // Planned 08:00-17:00 with a 60-minute break; in at 08:25, out at 18:30 local.
        $day = $this->compute('2026-10-14', [['in', '08:25'], ['out', '18:30']], $this->planned('2026-10-14', '08:00', '17:00', 60));

        // 605 minutes at work, less the 60-minute break: 545 worked, 480 regular, 65 overtime.
        $this->assertSame([545, 480, 65], [$day['worked_minutes'], $day['regular_minutes'], $day['overtime_minutes']]);
        $this->assertSame([25, 0, 0, 0], [$day['late_minutes'], $day['early_leave_minutes'], $day['night_minutes'], $day['holiday_minutes']]);
        $this->assertSame(['present', ['late']], [$day['status'], $day['exceptions']]);

        // Within the grace: no lateness recorded.
        $this->assertSame(0, $this->compute('2026-10-14', [['in', '08:09'], ['out', '17:00']], $this->planned('2026-10-14', '08:00', '17:00', 60))['late_minutes']);
    }

    public function test_a_night_shift_crossing_midnight_counts_night_minutes_and_leaving_early(): void
    {
        // Planned 21:00-05:00, worked 21:00 to 04:30 the next morning.
        $day = $this->compute('2026-10-14', [['in', '21:00'], ['out', '04:30', '2026-10-15']], $this->planned('2026-10-14', '21:00', '05:00', 0));

        $this->assertSame(450, $day['worked_minutes']);
        // 22:00 to 04:30 is inside the night window.
        $this->assertSame(390, $day['night_minutes']);
        $this->assertSame([30, ['early_leave']], [$day['early_leave_minutes'], $day['exceptions']]);
    }

    public function test_weekly_overtime_counts_the_weeks_earlier_regular_minutes(): void
    {
        // 2,400 regular minutes already this week; 8 h today: 300 to the 45-hour mark, 180 beyond it.
        $day = $this->compute('2026-10-16', [['in', '08:00'], ['out', '16:00']], null, weekBefore: 2400);

        $this->assertSame([480, 300, 180], [$day['worked_minutes'], $day['regular_minutes'], $day['overtime_minutes']]);
        $this->assertSame(['unplanned'], $day['exceptions']);
    }

    public function test_public_holiday_and_rest_day_minutes_are_overlays_of_worked_time(): void
    {
        // Tuesday 20 October 2026 is a holiday here.
        $holiday = $this->compute('2026-10-20', [['in', '09:00'], ['out', '13:00']], null, holidays: ['2026-10-20']);
        $this->assertSame([240, 240, 240, 0], [$holiday['worked_minutes'], $holiday['regular_minutes'], $holiday['holiday_minutes'], $holiday['rest_day_minutes']]);

        // Sunday 18 October: a rest day.
        $this->assertSame(240, $this->compute('2026-10-18', [['in', '09:00'], ['out', '13:00']], null)['rest_day_minutes']);
    }

    public function test_absence_holiday_leave_and_unpaired_punches(): void
    {
        $planned = $this->planned('2026-10-14', '08:00', '17:00', 60);

        // No punches for a shift that ended: absent; not yet ended: no day yet.
        $this->assertSame(['absent', ['absent']], array_values(array_intersect_key($this->compute('2026-10-14', [], $planned, now: '2026-10-14 18:00'), array_flip(['status', 'exceptions']))));
        $this->assertNull($this->compute('2026-10-14', [], $planned, now: '2026-10-14 12:00'));
        // A planned shift on a public holiday, or on approved leave, is no absence.
        $this->assertSame('holiday', $this->compute('2026-10-14', [], $planned, holidays: ['2026-10-14'], now: '2026-10-15 09:00')['status']);
        $this->assertSame('leave', $this->compute('2026-10-14', [], $planned, onLeave: true, now: '2026-10-15 09:00')['status']);
        // Nothing planned, nothing punched: no day.
        $this->assertNull($this->compute('2026-10-14', [], null));

        // An in with no out: incomplete, nothing counted.
        $open = $this->compute('2026-10-14', [['in', '08:00']], $planned);
        $this->assertSame(['incomplete', ['missing_out'], 0], [$open['status'], $open['exceptions'], $open['worked_minutes']]);
        $stray = $this->compute('2026-10-14', [['out', '17:00']], $planned);
        $this->assertSame(['incomplete', ['missing_in']], [$stray['status'], $stray['exceptions']]);

        // Without a time rule: all regular, flagged.
        $plain = DayCalculator::compute('2026-10-14', self::TZ, $this->punches('2026-10-14', [['in', '08:00'], ['out', '19:00']]), null, null, fn () => false, 0, false, CarbonImmutable::parse('2026-10-15'));
        $this->assertSame([660, 660, 0, ['unplanned', 'no_time_rule']], [$plain['worked_minutes'], $plain['regular_minutes'], $plain['overtime_minutes'], $plain['exceptions']]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $punches
     * @param  list<string>  $holidays
     */
    private function compute(string $date, array $punches, ?array $planned, int $weekBefore = 0, array $holidays = [], bool $onLeave = false, string $now = '2026-10-20 23:00'): ?array
    {
        return DayCalculator::compute(
            $date, self::TZ, $this->punches($date, $punches), $planned, self::RULE,
            fn (string $day) => in_array($day, $holidays, true), $weekBefore, $onLeave, CarbonImmutable::parse($now, self::TZ),
        );
    }

    /** @param list<array{0: string, 1: string, 2?: string}> $punches */
    private function punches(string $date, array $punches): array
    {
        return array_map(fn (array $p) => ['kind' => $p[0], 'at' => CarbonImmutable::parse(($p[2] ?? $date).' '.$p[1], self::TZ)->utc()], $punches);
    }

    private function planned(string $date, string $start, string $end, int $break): array
    {
        $from = CarbonImmutable::parse("{$date} {$start}", self::TZ);
        $to = CarbonImmutable::parse("{$date} {$end}", self::TZ);

        return ['start' => $from->utc(), 'end' => ($to->lessThanOrEqualTo($from) ? $to->addDay() : $to)->utc(), 'break' => $break];
    }
}
