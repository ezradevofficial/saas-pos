<?php

namespace App\Core\Workflow\Calendar;

/**
 * The calendar time limits are counted in (WF-09, APR-05): a time zone,
 * working hours per weekday (`mon` .. `sun` => list of ["HH:MM", "HH:MM"],
 * an empty list or a missing day is closed) and the country whose public
 * holidays close whole days (null: none).
 */
final class CalendarSpec
{
    /** @param array<string, list<array{0: string, 1: string}>> $hours */
    public function __construct(
        public readonly string $timezone,
        public readonly array $hours,
        public readonly ?string $country,
    ) {}
}
