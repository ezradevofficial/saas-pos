<?php

namespace Modules\HR\Leave;

use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Modules\HR\Models\LeaveType;

/**
 * HR-14: the days a leave range counts. `working_days`: days the company
 * is open (core business hours, closed on its country's public holidays,
 * CP-01); `calendar_days`: every day. A half day at either end counts 0.5
 * (a one-day range with both halves counts 0.5).
 */
class LeaveDays
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** @return list<array{date: string, fraction: string}> */
    public function dates(LeaveType $type, string $companyId, string $start, string $end, bool $startHalf, bool $endHalf): array
    {
        $spec = $this->calendar->forCompany($companyId);
        $from = CarbonImmutable::parse($start, $spec->timezone)->setTime(12, 0);
        $to = CarbonImmutable::parse($end, $spec->timezone)->setTime(12, 0);
        $dates = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($type->count_basis === 'working_days' && $this->calendar->openIntervals($day, $spec) === []) {
                continue;
            }

            $half = ($day->isSameDay($from) && $startHalf) || ($day->isSameDay($to) && $endHalf);
            $dates[] = ['date' => $day->toDateString(), 'fraction' => $half ? '0.50' : '1.00'];
        }

        return $dates;
    }

    /** @param list<array{date: string, fraction: string}> $dates */
    public static function total(array $dates): string
    {
        $cents = array_sum(array_map(fn (array $d) => (int) round(((float) $d['fraction']) * 100), $dates));

        return number_format($cents / 100, 2, '.', '');
    }
}
