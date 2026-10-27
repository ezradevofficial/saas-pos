<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/stays/{hotel_stay}/assign-room (HOT-17): `hotel.stay.checkin` at the stay's branch. `{room_id}`: a room of the same branch; one of another type is an upgrade or downgrade, recorded. */
class AssignRoomRequest extends HotelRequest
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
        return ['room_id' => HotelRules::id('hotel_rooms')];
    }
}
