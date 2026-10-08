<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Calendar\BusinessCalendar;
use Closure;

/**
 * WF-09, APR-05: PUT companies/{company}/business-hours {hours}: the
 * company's working hours per weekday, in its time zone
 * ({"mon": [["08:00", "17:00"]], ..., "sun": []}; a day left out is closed).
 */
class UpdateBusinessHoursRequest extends BusinessHoursRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', function (string $attribute, mixed $value, Closure $fail) {
                foreach (BusinessCalendar::problems($value) as $problem) {
                    $fail($problem);
                }
            }],
        ];
    }

    /** @return array<string, list<array{0: string, 1: string}>> every weekday, closed days empty */
    public function hours(): array
    {
        $hours = (array) $this->input('hours');
        $all = [];

        foreach (BusinessCalendar::DAYS as $day) {
            $intervals = $hours[$day] ?? [];
            usort($intervals, fn ($a, $b) => strcmp($a[0], $b[0]));
            $all[$day] = array_values($intervals);
        }

        return $all;
    }
}
