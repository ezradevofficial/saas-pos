<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/room-charges/lookup (HOT-22): `hotel.folio.view` at the branch. `?branch_id=&room=&amount_minor?&currency?`: the in-house guest of a room number and whether a charge would be accepted. */
class RoomChargeLookupRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.view';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.folio.view'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'room' => ['required', 'string', 'max:20'],
            'amount_minor' => ['sometimes', 'integer', 'min:1', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
