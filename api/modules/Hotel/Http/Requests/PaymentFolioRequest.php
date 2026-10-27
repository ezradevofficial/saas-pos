<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST hotel/folios/{hotel_folio}/payments (HOT-14, HOT-24): `hotel.folio.payment` at the folio's branch.
 * `{payment_method_id, amount_minor, kind?: payment|deposit, mode?: direct|stk, phone? (stk), reference?}`. A mobile money method with a provider
 * can start an M-Pesa push (`mode: stk`); the payment waits `pending` and posts when the provider settles. The method must be the folio
 * company's, active, and in the folio's currency.
 */
class PaymentFolioRequest extends HotelRequest
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
        return [
            'payment_method_id' => HotelRules::id('payment_methods'),
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'kind' => ['sometimes', 'string', Rule::in(['payment', 'deposit'])],
            'mode' => ['sometimes', 'string', Rule::in(['direct', 'stk'])],
            'phone' => ['required_if:mode,stk', 'nullable', 'string', 'max:30'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
