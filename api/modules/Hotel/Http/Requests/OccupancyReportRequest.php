<?php

namespace Modules\Hotel\Http\Requests;

use Carbon\CarbonImmutable;
use Modules\Hotel\Reports\OccupancyReport;

/** GET hotel/reports/occupancy (HOT-31): `hotel.report.view` at the branch. `?branch_id=&from=&to=` (at most a year): per day the rooms available and sold, occupancy, ADR and RevPAR; the totals. */
class OccupancyReportRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.report.view';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.report.view'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'from' => HotelRules::day(),
            'to' => [...HotelRules::day(), 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->filled('from') && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($value)) >= OccupancyReport::MAX_DAYS) {
                    $fail(__('hotel.errors.range_too_long', ['days' => OccupancyReport::MAX_DAYS]));
                }
            }],
        ];
    }
}
