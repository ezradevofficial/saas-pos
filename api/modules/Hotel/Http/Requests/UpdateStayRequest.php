<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * PATCH hotel/stays/{hotel_stay} (HOT-37): `hotel.reservation.edit` at the stay's branch. `{arrival_on?, departure_on?, adults?, children?,
 * room_type_id?, rate_plan_id?, notes?, bill_to_master?, rate_minor?, rate_reason?}`. A guest in house may only change the departure, party and
 * notes. A typed rate needs `hotel.rate.override` and a reason. Availability is re-checked under the locks.
 */
class UpdateStayRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.edit';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_stay')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'arrival_on' => HotelRules::day(false),
            'departure_on' => HotelRules::day(false),
            'adults' => ['sometimes', 'integer', 'between:1,20'],
            'children' => ['sometimes', 'integer', 'between:0,20'],
            'room_type_id' => ['sometimes', 'uuid', Rule::exists('hotel_room_types', 'id')],
            'rate_plan_id' => ['sometimes', 'uuid', Rule::exists('hotel_rate_plans', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bill_to_master' => ['sometimes', 'boolean'],
            'rate_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'rate_reason' => ['required_with:rate_minor', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->filled('rate_minor') && ! app(Access::class)->can($this->user(), 'hotel.rate.override', $this->bound('hotel_stay')->branch_id)) {
                $validator->errors()->add('rate_minor', __('hotel.errors.rate_override_forbidden'));
            }
        }];
    }
}
