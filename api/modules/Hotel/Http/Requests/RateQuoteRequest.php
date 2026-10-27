<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Support\Access;

/**
 * GET hotel/rate-quote (HOT-10): `hotel.rate.view` at the plan's company.
 * `?rate_plan_id=&arrival_on=&departure_on=&adults=&children=`; the plan names the room type.
 */
class RateQuoteRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.rate.view';
    }

    public function rules(): array
    {
        return [
            'rate_plan_id' => HotelRules::id('hotel_rate_plans'),
            'arrival_on' => HotelRules::day(),
            'departure_on' => [...HotelRules::day(), 'after:arrival_on'],
            'adults' => ['sometimes', 'integer', 'between:1,20'],
            'children' => ['sometimes', 'integer', 'between:0,20'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->requireCompany($this->user(), 'hotel.rate.view', (string) RatePlan::query()->whereKey($this->input('rate_plan_id'))->value('company_id'));
            }
        }];
    }
}
