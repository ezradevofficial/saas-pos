<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/stays/{hotel_stay}/check-in (HOT-17, HOT-18): `hotel.stay.checkin` at the stay's branch. `{room_id?, keys_issued?, waive_fees?}`;
 * waiving the early check-in fee needs `hotel.fee.waive`. Checking in before the booked arrival date needs `hotel.stay.checkin_early`.
 */
class CheckInRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.stay.checkin';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_stay')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hotel_rooms', 'id')],
            'keys_issued' => ['sometimes', 'integer', 'between:0,20'],
            'waive_fees' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->boolean('waive_fees') && ! app(Access::class)->can($this->user(), 'hotel.fee.waive', $this->bound('hotel_stay')->branch_id)) {
                $validator->errors()->add('waive_fees', __('hotel.errors.waive_forbidden'));
            }
        }];
    }

    public function early(): bool
    {
        return app(Access::class)->can($this->user(), 'hotel.stay.checkin_early', $this->bound('hotel_stay')->branch_id);
    }
}
