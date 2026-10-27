<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/reservations/{hotel_reservation} (HOT-12): `hotel.reservation.view` at the booking's branch. */
class ShowReservationRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.view';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_reservation')?->branch_id;
    }

    public function rules(): array
    {
        return [];
    }
}
