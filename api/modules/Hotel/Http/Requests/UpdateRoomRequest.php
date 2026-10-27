<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\RoomType;

/** PATCH hotel/rooms/{hotel_room} (HOT-04): `hotel.room.manage` at the room's branch. A room with a guest keeps its type. */
class UpdateRoomRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.manage';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_room')?->branch_id;
    }

    public function rules(): array
    {
        $room = $this->bound('hotel_room');

        return [
            'room_type_id' => ['sometimes', 'uuid', Rule::exists('hotel_room_types', 'id')],
            'number' => ['sometimes', 'string', 'max:20', HotelRules::unique('hotel_rooms', 'number', ['branch_id' => $room->branch_id], $room->id)],
            ...RoomFields::rules(),
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('room_type_id')) {
                return;
            }

            $room = $this->bound('hotel_room');
            $type = RoomType::query()->find($this->input('room_type_id'));

            if ($type === null || $type->company_id !== $room->company_id || $type->archived_at !== null) {
                $validator->errors()->add('room_type_id', __('hotel.errors.room_type_archived'));
            }
        }];
    }
}
