<?php

namespace App\Core\Workflow\Calendar;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Business time for stage due times (WF-09) and approval escalation
 * (APR-05): a company's working hours (business_hours, default Monday to
 * Friday 08:00-17:00) in the company's time zone, closed on its country's
 * public holidays (country-pack data, CP-01).
 *
 * Units: `business_hours` count only open minutes; `business_days` land on
 * the same local time N working days later (from outside working hours,
 * counting starts at the next opening; a time the target day is not open
 * moves to that day's nearest open edge); `hours` and `days` are plain
 * elapsed time.
 */
class BusinessCalendar
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DEFAULT_HOURS = [
        'mon' => [['08:00', '17:00']],
        'tue' => [['08:00', '17:00']],
        'wed' => [['08:00', '17:00']],
        'thu' => [['08:00', '17:00']],
        'fri' => [['08:00', '17:00']],
        'sat' => [],
        'sun' => [],
    ];

    /** Days searched for the next open minute before giving up (a calendar with no open day). */
    private const HORIZON_DAYS = 3660;

    /** @var array<string, array<string, true>> "country|year" => set of Y-m-d */
    private array $holidays = [];

    /** The calendar of a company (UTC with default hours and no holidays without one). */
    public function forCompany(?string $companyId): CalendarSpec
    {
        $company = $companyId === null ? null : Company::query()->find($companyId, ['id', 'timezone', 'country']);

        if ($company === null) {
            return new CalendarSpec('UTC', self::DEFAULT_HOURS, null);
        }

        $hours = BusinessHours::query()->where('company_id', $company->id)->value('hours');

        return new CalendarSpec(
            $company->timezone ?: 'UTC',
            is_array($hours) ? $hours : (is_string($hours) ? json_decode($hours, true) : self::DEFAULT_HOURS),
            $company->country,
        );
    }

    /** When something started at $from with a limit of $amount $unit is due, in UTC. */
    public function due(DateTimeInterface $from, int $amount, string $unit, CalendarSpec $spec): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from);

        return match ($unit) {
            'business_hours' => $this->addBusinessMinutes($start, $amount * 60, $spec),
            'business_days' => $this->addBusinessDays($start, $amount, $spec),
            'hours' => $start->addHours($amount)->utc(),
            'days' => $start->addDays($amount)->utc(),
            default => throw new InvalidArgumentException("Unknown time unit [{$unit}]."),
        };
    }

    public function addBusinessMinutes(DateTimeInterface $from, int $minutes, CalendarSpec $spec): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from)->setTimezone($spec->timezone);
        $remaining = $minutes;

        if ($remaining <= 0) {
            return $at->utc();
        }

        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            foreach ($this->openIntervals($at, $spec) as [$open, $close]) {
                $start = $at->greaterThan($open) ? $at : $open;

                if ($start->greaterThanOrEqualTo($close)) {
                    continue;
                }

                $available = (int) $start->diffInMinutes($close, true);

                if ($remaining <= $available) {
                    return $start->addMinutes($remaining)->utc();
                }

                $remaining -= $available;
                $at = $close;
            }

            $at = $at->startOfDay()->addDay();
        }

        throw new InvalidArgumentException('The calendar has no working time.');
    }

    public function addBusinessDays(DateTimeInterface $from, int $days, CalendarSpec $spec): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from)->setTimezone($spec->timezone);

        if (! $this->isOpenAt($at, $spec)) {
            $at = $this->nextOpening($at, $spec);
        }

        $day = $at->startOfDay();

        for ($counted = 0, $i = 0; $counted < $days; $i++) {
            if ($i >= self::HORIZON_DAYS) {
                throw new InvalidArgumentException('The calendar has no working days.');
            }

            $day = $day->addDay();

            if ($this->openIntervals($day, $spec) !== []) {
                $counted++;
            }
        }

        $target = $day->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));
        $intervals = $this->openIntervals($day, $spec);

        if ($intervals === [] || $this->isOpenAt($target, $spec)) {
            return $target->utc();
        }

        // Not open at that time on the target day: the nearest open edge.
        if ($target->lessThan($intervals[0][0])) {
            return $intervals[0][0]->utc();
        }

        $edge = $intervals[0][1];

        foreach ($intervals as [$open, $close]) {
            if ($target->greaterThanOrEqualTo($close)) {
                $edge = $close;
            } elseif ($target->lessThan($open)) {
                return $open->utc();
            }
        }

        return $edge->utc();
    }

    public function isOpenAt(DateTimeInterface $at, CalendarSpec $spec): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($spec->timezone);

        foreach ($this->openIntervals($local, $spec) as [$open, $close]) {
            if ($local->greaterThanOrEqualTo($open) && $local->lessThan($close)) {
                return true;
            }
        }

        return false;
    }

    public function nextOpening(DateTimeInterface $from, CalendarSpec $spec): CarbonImmutable
    {
        $at = CarbonImmutable::instance($from)->setTimezone($spec->timezone);

        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            foreach ($this->openIntervals($at, $spec) as [$open, $close]) {
                if ($at->lessThan($close)) {
                    return $at->greaterThan($open) ? $at : $open;
                }
            }

            $at = $at->startOfDay()->addDay();
        }

        throw new InvalidArgumentException('The calendar has no working time.');
    }

    /** True when $date (a local calendar day) is a public holiday in $country. */
    public function isHoliday(DateTimeInterface $date, ?string $country): bool
    {
        if ($country === null) {
            return false;
        }

        $day = CarbonImmutable::instance($date);

        return isset($this->holidaysOf($country, $day->year)[$day->format('Y-m-d')]);
    }

    /**
     * The open intervals of $day's local date, as local times, sorted; none
     * on a closed weekday or a public holiday.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function openIntervals(CarbonImmutable $day, CalendarSpec $spec): array
    {
        $local = $day->setTimezone($spec->timezone);

        if ($this->isHoliday($local, $spec->country)) {
            return [];
        }

        $key = self::DAYS[$local->dayOfWeekIso - 1];
        $intervals = [];

        foreach ($spec->hours[$key] ?? [] as [$open, $close]) {
            [$oh, $om] = array_map('intval', explode(':', $open));
            [$ch, $cm] = array_map('intval', explode(':', $close));
            $intervals[] = [$local->setTime($oh, $om), $ch === 24 ? $local->startOfDay()->addDay() : $local->setTime($ch, $cm)];
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $intervals;
    }

    /**
     * Problems with a working hours value (empty when valid): unknown days,
     * times that are not HH:MM (24:00 allowed as a close), an interval that
     * does not end after it starts, or overlapping intervals.
     *
     * @return list<string> translated
     */
    public static function problems(mixed $hours): array
    {
        if (! is_array($hours) || ($hours !== [] && array_is_list($hours))) {
            return [__('workflow.business_hours.invalid')];
        }

        $problems = [];

        foreach ($hours as $day => $intervals) {
            if (! in_array($day, self::DAYS, true) || ! is_array($intervals) || ! array_is_list($intervals) || count($intervals) > 4) {
                $problems[] = __('workflow.business_hours.invalid_day', ['day' => (string) $day]);

                continue;
            }

            $spans = [];

            foreach ($intervals as $interval) {
                $valid = is_array($interval) && array_is_list($interval) && count($interval) === 2
                    && is_string($interval[0]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $interval[0]) === 1
                    && is_string($interval[1]) && preg_match('/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/', $interval[1]) === 1
                    && $interval[0] < $interval[1];

                if (! $valid) {
                    $problems[] = __('workflow.business_hours.invalid_interval', ['day' => $day]);

                    continue 2;
                }

                $spans[] = $interval;
            }

            usort($spans, fn ($a, $b) => strcmp($a[0], $b[0]));

            for ($i = 1; $i < count($spans); $i++) {
                if ($spans[$i][0] < $spans[$i - 1][1]) {
                    $problems[] = __('workflow.business_hours.overlap', ['day' => $day]);
                }
            }
        }

        return $problems;
    }

    /** @return array<string, true> */
    private function holidaysOf(string $country, int $year): array
    {
        $key = $country.'|'.$year;

        if (isset($this->holidays[$key])) {
            return $this->holidays[$key];
        }

        $days = [];
        $jan1 = sprintf('%04d-01-01', $year);
        $dec31 = sprintf('%04d-12-31', $year);

        $rows = PublicHoliday::query()->where('country', $country)
            ->where(fn ($q) => $q->whereNull('date')->orWhereBetween('date', [$jan1, $dec31]))
            ->get();

        foreach ($rows as $row) {
            if ($row->date !== null) {
                $days[substr((string) $row->date, 0, 10)] = true;

                continue;
            }

            if (! checkdate($row->month, $row->day, $year)) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $year, $row->month, $row->day);
            $from = $row->effective_from === null ? null : substr((string) $row->effective_from, 0, 10);
            $to = $row->effective_to === null ? null : substr((string) $row->effective_to, 0, 10);

            if (($from === null || $date >= $from) && ($to === null || $date <= $to)) {
                $days[$date] = true;
            }
        }

        return $this->holidays[$key] = $days;
    }
}
