<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/folios/{hotel_folio}/payments/{hotel_payment}/check (HOT-24): `hotel.folio.payment` at the folio's branch; asks the provider where a pending M-Pesa push stands. */
class CheckPaymentRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.payment';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return [];
    }
}
