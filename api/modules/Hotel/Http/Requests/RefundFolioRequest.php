<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/folios/{hotel_folio}/refunds (HOT-24): `hotel.folio.refund` at the folio's branch, within the user's refund limit (RBAC-06). `{payment_method_id, amount_minor, reason, reference?}`; never more than the credit on the folio. */
class RefundFolioRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.refund';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => HotelRules::id('payment_methods'),
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'reason' => HotelRules::reason(),
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
