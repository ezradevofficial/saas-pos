<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/walk-ins (HOT-12, HOT-17): a booking and its check-in in one step, for a guest at the desk now. `hotel.reservation.create` and
 * `hotel.stay.checkin` at the branch. `{branch_id, guest_id | guest, room_id, rate_plan_id, departure_on, adults?, children?, rate_minor?, rate_reason?,
 * keys_issued?, waive_fees?, source?, notes?}`; the stay starts on the property's business date in the room's type.
 */
class WalkInRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.create';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.stay.checkin'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'guest_id' => ['required_without:guest', 'uuid', Rule::exists('hotel_guests', 'id')->whereNull('archived_at')],
            'guest' => ['required_without:guest_id', 'array'],
            ...GuestFields::rules('guest.'),
            'room_id' => HotelRules::id('hotel_rooms'),
            'rate_plan_id' => HotelRules::id('hotel_rate_plans'),
            'departure_on' => HotelRules::day(),
            'adults' => ['sometimes', 'integer', 'between:1,20'],
            'children' => ['sometimes', 'integer', 'between:0,20'],
            'rate_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'rate_reason' => ['required_with:rate_minor', 'nullable', 'string', 'max:500'],
            'keys_issued' => ['sometimes', 'integer', 'between:0,20'],
            'waive_fees' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', 'in:walk_in,phone,agent,other'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $access = app(Access::class);
            $branch = (string) $this->input('branch_id');

            if (! $access->can($this->user(), 'hotel.reservation.create', $branch)) {
                $validator->errors()->add('branch_id', __('hotel.errors.place_forbidden'));
            }

            if ($this->filled('rate_minor') && ! $access->can($this->user(), 'hotel.rate.override', $branch)) {
                $validator->errors()->add('rate_minor', __('hotel.errors.rate_override_forbidden'));
            }

            if ($this->boolean('waive_fees') && ! $access->can($this->user(), 'hotel.fee.waive', $branch)) {
                $validator->errors()->add('waive_fees', __('hotel.errors.waive_forbidden'));
            }
        }];
    }
}
