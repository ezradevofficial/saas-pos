<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/room-types/{hotel_room_type}/archive (HOT-03, TEN-06): `hotel.room.manage` at the type's company. */
class ArchiveRoomTypeRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.manage';
    }

    protected function companyId(): ?string
    {
        return $this->bound('hotel_room_type')?->company_id;
    }

    public function rules(): array
    {
        return [];
    }
}
