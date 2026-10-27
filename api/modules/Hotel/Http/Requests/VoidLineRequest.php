<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/folios/{hotel_folio}/lines/{hotel_line}/void (HOT-21): `hotel.folio.void` at the folio's branch. `{reason}`; a reversing line, never a delete. A charge from a till is reversed by the sale's void or refund. */
class VoidLineRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.void';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return ['reason' => HotelRules::reason()];
    }
}
