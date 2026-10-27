<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/rooms/{hotel_room}/archive (HOT-04, TEN-06): `hotel.room.manage` at the room's branch. */
class ArchiveRoomRequest extends HotelRequest
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
        return [];
    }
}
