<?php

namespace Modules\Hotel\Http\Requests;

/**
 * POST hotel/room-types (HOT-03): `hotel.room.manage` at the company.
 * `{company_id, code, name, description?, base_adults?, max_adults?, max_children?, max_occupancy?, beds?, sort?}`.
 */
class StoreRoomTypeRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.manage';
    }

    protected function companies(): array
    {
        return ['company_id' => 'hotel.room.manage'];
    }

    public function rules(): array
    {
        return [
            'company_id' => HotelRules::company(),
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/', HotelRules::unique('hotel_room_types', 'code', ['company_id' => $this->input('company_id')])],
            ...RoomTypeFields::rules(),
        ];
    }
}
