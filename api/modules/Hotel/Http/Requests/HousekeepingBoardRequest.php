<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/housekeeping/board (HOT-28): `hotel.housekeeping.view` at the branch. `?branch_id=`: rooms by floor with their status, open tasks and the stay around them. */
class HousekeepingBoardRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.view';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.housekeeping.view'];
    }

    public function rules(): array
    {
        return ['branch_id' => HotelRules::branch()];
    }
}
