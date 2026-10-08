<?php

namespace App\Core\Automation\Triggers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * AUTO-01 "schedule (cron-like, set in plain terms)": a recurrence stored
 * as structured JSON, never cron text, read in a time zone (the rule's
 * company's):
 *
 *   {"every": "day",   "time": "08:00"}
 *   {"every": "week",  "time": "08:00", "days": ["mon", "thu"]}
 *   {"every": "month", "time": "08:00", "day": 31}     the last day in shorter months
 *
 * Occurrences are local wall-clock times: 08:00 stays 08:00 across a
 * daylight saving change; a time that does not exist that day (skipped by
 * the clocks going forward) runs at the first valid time after it.
 */
final class ScheduleRecurrence
{
    public const EVERY = ['day', 'week', 'month'];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @return list<string> translation keys of the problems (empty when valid) */
    public static function problems(array $schedule): array
    {
        $problems = [];
        $every = $schedule['every'] ?? null;

        if (! in_array($every, self::EVERY, true)) {
            return ['automation.validation.schedule_every'];
        }

        if (! is_string($schedule['time'] ?? null) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $schedule['time']) !== 1) {
            $problems[] = 'automation.validation.schedule_time';
        }

        if ($every === 'week') {
            $days = $schedule['days'] ?? null;

            if (! is_array($days) || ! array_is_list($days) || $days === [] || array_diff($days, self::DAYS) !== [] || count(array_unique($days)) !== count($days)) {
                $problems[] = 'automation.validation.schedule_days';
            }
        }

        if ($every === 'month' && (! is_int($schedule['day'] ?? null) || $schedule['day'] < 1 || $schedule['day'] > 31)) {
            $problems[] = 'automation.validation.schedule_day';
        }

        $allowed = ['every', 'time', ...match ($every) {
            'week' => ['days'],
            'month' => ['day'],
            default => [],
        }];

        if (array_diff(array_keys($schedule), [...$allowed, 'type']) !== []) {
            $problems[] = 'automation.validation.schedule_extra';
        }

        return $problems;
    }

    /** The first occurrence strictly after $after, in UTC. */
    public static function next(array $schedule, DateTimeInterface $after, string $timezone): CarbonImmutable
    {
        if (self::problems($schedule) !== []) {
            throw new InvalidArgumentException('Invalid schedule.');
        }

        $after = CarbonImmutable::instance($after)->utc();
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
        $day = $after->setTimezone($timezone)->startOfDay();

        // A month day (up to 31) recurs within 2 months; 400 days covers every case.
        for ($i = 0; $i <= 400; $i++) {
            $candidate = $day->addDays($i);

            if (! self::matches($schedule, $candidate)) {
                continue;
            }

            $at = CarbonImmutable::create($candidate->year, $candidate->month, $candidate->day, $hour, $minute, 0, $timezone);

            if ($at->greaterThan($after)) {
                return $at->utc();
            }
        }

        throw new InvalidArgumentException('The schedule never recurs.');
    }

    private static function matches(array $schedule, CarbonImmutable $day): bool
    {
        return match ($schedule['every']) {
            'week' => in_array(self::DAYS[$day->dayOfWeekIso - 1], $schedule['days'], true),
            'month' => $day->day === min((int) $schedule['day'], $day->daysInMonth),
            default => true,
        };
    }
}
