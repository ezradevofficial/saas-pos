<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/stays/{hotel_stay}/move-room (HOT-37): `hotel.stay.move` at the stay's branch. `{room_id, reason}`: the old room becomes dirty with a clean task, the new one occupied. */
class MoveRoomRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.stay.move';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_stay')?->branch_id;
    }

    public function rules(): array
    {
        return ['room_id' => HotelRules::id('hotel_rooms'), 'reason' => HotelRules::reason()];
    }
}
