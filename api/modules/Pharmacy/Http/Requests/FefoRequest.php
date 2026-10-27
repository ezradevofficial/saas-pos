<?php

namespace Modules\Pharmacy\Http\Requests;

/**
 * GET pharmacy/fefo (PHA-07): `pharmacy.lot.view` at the location; `?item_id=`,
 * `?location_id=` and an optional `?qty=` (base unit) to see how to cover it
 * first-expiry-first-out.
 */
class FefoRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.lot.view';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.lot.view'];
    }

    public function rules(): array
    {
        return [
            'item_id' => PharmacyRules::item(),
            'location_id' => PharmacyRules::location(),
            'qty' => ['sometimes', 'string', PharmacyRules::QTY],
        ];
    }
}
