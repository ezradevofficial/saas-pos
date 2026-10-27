<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Hotel\Models\Room;

/** GET hotel/rooms (HOT-04): `hotel.room.view` at the reader's branches; `?branch=`, `?room_type=`, `?status=`, `?floor=`, `?search=`, `?archived=1`. */
class ListRoomsRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.view';
    }

    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'room_type' => ['sometimes', 'uuid', Rule::exists('hotel_room_types', 'id')],
            'status' => ['sometimes', 'string', Rule::in(Room::STATUSES)],
            'floor' => ['sometimes', 'string', 'max:20'],
            'search' => ['sometimes', 'string', 'max:100'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
