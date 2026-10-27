<?php

namespace Modules\Hotel\Http\Requests;

/**
 * GET hotel/guests/{hotel_guest} (HOT-16, HOT-38): `hotel.guest.view` at the guest's company. `?reveal=1` shows the identity document in full
 * and needs `hotel.guest.identity` (and field rules that do not hide it); the view is audited once.
 */
class ShowGuestRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.guest.view';
    }

    protected function companyId(): ?string
    {
        return $this->bound('hotel_guest')?->company_id;
    }

    public function rules(): array
    {
        return ['reveal' => ['sometimes', 'boolean']];
    }

    public function reveals(): bool
    {
        return $this->boolean('reveal');
    }
}
