<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Support\Access;

/** POST hotel/housekeeping/tasks (HOT-28): `hotel.housekeeping.assign` at the room's branch. `{room_id, kind, business_date?, assigned_to?, notes?}`; one open task of a kind per room and date. */
class StoreTaskRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.assign';
    }

    public function rules(): array
    {
        return [
            'room_id' => HotelRules::id('hotel_rooms'),
            'kind' => ['required', 'string', Rule::in(HousekeepingTask::KINDS)],
            'business_date' => HotelRules::day(false),
            'assigned_to' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->require($this->user(), 'hotel.housekeeping.assign', (string) Room::query()->whereKey($this->input('room_id'))->value('branch_id'));
            }
        }];
    }
}
