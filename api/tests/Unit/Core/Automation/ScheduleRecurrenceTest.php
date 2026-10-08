<?php

namespace Tests\Unit\Core\Automation;

use App\Core\Automation\Triggers\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUTO-01 schedules in plain terms: the next occurrence of every day,
 * week (on days) and month (on a day, the last day in shorter months) at
 * a local time, in the company's time zone, across daylight saving
 * changes; and which schedules are refused.
 */
class ScheduleRecurrenceTest extends TestCase
{
    /** @return array<string, array{array, string, string, string}> schedule, after (UTC), zone, expected (UTC) */
    public static function occurrences(): array
    {
        return [
            'daily, later today' => [['every' => 'day', 'time' => '08:00'], '2026-10-08T04:00:00Z', 'Africa/Nairobi', '2026-10-08T05:00:00Z'],
            'daily, exactly now is not after' => [['every' => 'day', 'time' => '08:00'], '2026-10-08T05:00:00Z', 'Africa/Nairobi', '2026-10-09T05:00:00Z'],
            'daily, tomorrow' => [['every' => 'day', 'time' => '08:00'], '2026-10-08T06:00:00Z', 'Africa/Nairobi', '2026-10-09T05:00:00Z'],
            'daily in Kinshasa (UTC+1)' => [['every' => 'day', 'time' => '08:00'], '2026-10-08T06:00:00Z', 'Africa/Kinshasa', '2026-10-08T07:00:00Z'],
            'daily, the local day differs from UTC' => [['every' => 'day', 'time' => '01:30'], '2026-10-08T21:00:00Z', 'Africa/Nairobi', '2026-10-08T22:30:00Z'],
            'weekly, next listed day' => [['every' => 'week', 'days' => ['mon', 'thu'], 'time' => '08:00'], '2026-10-08T06:00:00Z', 'Africa/Nairobi', '2026-10-12T05:00:00Z'],
            'weekly, same day later' => [['every' => 'week', 'days' => ['thu'], 'time' => '18:15'], '2026-10-08T06:00:00Z', 'Africa/Nairobi', '2026-10-08T15:15:00Z'],
            'weekly, Sunday' => [['every' => 'week', 'days' => ['sun'], 'time' => '00:00'], '2026-10-08T06:00:00Z', 'UTC', '2026-10-11T00:00:00Z'],
            'monthly, day 15' => [['every' => 'month', 'day' => 15, 'time' => '09:00'], '2026-10-08T06:00:00Z', 'Africa/Nairobi', '2026-10-15T06:00:00Z'],
            'monthly, day 31 in November is the 30th' => [['every' => 'month', 'day' => 31, 'time' => '09:00'], '2026-11-01T00:00:00Z', 'UTC', '2026-11-30T09:00:00Z'],
            'monthly, day 31 in February is the 28th' => [['every' => 'month', 'day' => 31, 'time' => '09:00'], '2027-02-01T00:00:00Z', 'UTC', '2027-02-28T09:00:00Z'],
            'monthly, day 29 in a leap February' => [['every' => 'month', 'day' => 29, 'time' => '09:00'], '2028-02-01T00:00:00Z', 'UTC', '2028-02-29T09:00:00Z'],
            'monthly, day passed: next month' => [['every' => 'month', 'day' => 1, 'time' => '09:00'], '2026-10-08T06:00:00Z', 'UTC', '2026-11-01T09:00:00Z'],
            'daily across the spring change keeps 08:00 local' => [['every' => 'day', 'time' => '08:00'], '2026-03-28T08:00:00Z', 'Europe/Paris', '2026-03-29T06:00:00Z'],
            'daily before the spring change' => [['every' => 'day', 'time' => '08:00'], '2026-03-27T08:00:00Z', 'Europe/Paris', '2026-03-28T07:00:00Z'],
            'a time skipped by the spring change runs right after it' => [['every' => 'day', 'time' => '02:30'], '2026-03-28T03:00:00Z', 'Europe/Paris', '2026-03-29T01:30:00Z'],
            'daily across the autumn change' => [['every' => 'day', 'time' => '08:00'], '2026-10-24T08:00:00Z', 'Europe/Paris', '2026-10-25T07:00:00Z'],
        ];
    }

    #[DataProvider('occurrences')]
    public function test_the_next_occurrence(array $schedule, string $after, string $zone, string $expected): void
    {
        $next = ScheduleRecurrence::next($schedule, CarbonImmutable::parse($after), $zone);

        $this->assertSame($expected, $next->toIso8601ZuluString());
        $this->assertSame('UTC', $next->timezoneName);
    }

    public function test_occurrences_follow_one_another(): void
    {
        $schedule = ['every' => 'week', 'days' => ['mon', 'wed', 'fri'], 'time' => '07:45'];
        $at = CarbonImmutable::parse('2026-10-08T00:00:00Z');
        $seen = [];

        for ($i = 0; $i < 4; $i++) {
            $at = ScheduleRecurrence::next($schedule, $at, 'Africa/Nairobi');
            $seen[] = $at->setTimezone('Africa/Nairobi')->format('D H:i');
        }

        $this->assertSame(['Fri 07:45', 'Mon 07:45', 'Wed 07:45', 'Fri 07:45'], $seen);
    }

    /** @return array<string, array{array, list<string>}> */
    public static function invalid(): array
    {
        return [
            'no frequency' => [['time' => '08:00'], ['automation.validation.schedule_every']],
            'cron text' => [['every' => '0 8 * * 1'], ['automation.validation.schedule_every']],
            'bad time' => [['every' => 'day', 'time' => '8am'], ['automation.validation.schedule_time']],
            'hour 24' => [['every' => 'day', 'time' => '24:00'], ['automation.validation.schedule_time']],
            'week without days' => [['every' => 'week', 'time' => '08:00'], ['automation.validation.schedule_days']],
            'unknown day' => [['every' => 'week', 'time' => '08:00', 'days' => ['monday']], ['automation.validation.schedule_days']],
            'repeated day' => [['every' => 'week', 'time' => '08:00', 'days' => ['mon', 'mon']], ['automation.validation.schedule_days']],
            'day 0' => [['every' => 'month', 'time' => '08:00', 'day' => 0], ['automation.validation.schedule_day']],
            'day as text' => [['every' => 'month', 'time' => '08:00', 'day' => '5'], ['automation.validation.schedule_day']],
            'days on a daily schedule' => [['every' => 'day', 'time' => '08:00', 'days' => ['mon']], ['automation.validation.schedule_extra']],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_schedules_are_refused(array $schedule, array $problems): void
    {
        $this->assertSame($problems, ScheduleRecurrence::problems($schedule));
    }

    public function test_a_trigger_type_key_is_allowed_alongside(): void
    {
        $this->assertSame([], ScheduleRecurrence::problems(['type' => 'schedule', 'every' => 'month', 'day' => 31, 'time' => '23:59']));
    }
}
