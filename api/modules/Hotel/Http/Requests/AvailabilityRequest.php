<?php

namespace Modules\Hotel\Http\Requests;

use Carbon\CarbonImmutable;

/** GET hotel/availability (HOT-11): `hotel.reservation.view` at the branch. `?branch_id=&from=&to=` (at most 62 days); the grid of room types by night and rooms by stay. */
class AvailabilityRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.view';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.reservation.view'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'from' => HotelRules::day(),
            'to' => [...HotelRules::day(), 'after:from', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->filled('from') && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($value)) > 62) {
                    $fail(__('hotel.errors.range_too_long', ['days' => 62]));
                }
            }],
        ];
    }
}
