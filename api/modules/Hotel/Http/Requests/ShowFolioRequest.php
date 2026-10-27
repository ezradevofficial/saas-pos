<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/folios/{hotel_folio} and its print (HOT-20): `hotel.folio.view` at the folio's branch. */
class ShowFolioRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.view';
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
