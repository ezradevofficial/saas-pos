<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST pharmacy/transfers (PHA-13): `pharmacy.lot.transfer` at both
 * locations; `{from_location_id, to_location_id, note?, lines: [{item_id,
 * uom_id?, qty, lots?: [{lot_id, qty}]}]}`. Without `lots` a line takes them
 * first-expiry-first-out; with them their quantities (base unit) must add up
 * to the line's.
 */
class StoreTransferRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.lot.transfer';
    }

    protected function places(): array
    {
        return ['from_location_id' => 'pharmacy.lot.transfer', 'to_location_id' => 'pharmacy.lot.transfer'];
    }

    public function rules(): array
    {
        return [
            'from_location_id' => PharmacyRules::location(),
            'to_location_id' => PharmacyRules::location(),
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => PharmacyRules::item(),
            'lines.*.uom_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            'lines.*.qty' => PharmacyRules::qty(),
            'lines.*.lots' => ['sometimes', 'array', 'max:50'],
            'lines.*.lots.*.lot_id' => PharmacyRules::lot(),
            'lines.*.lots.*.qty' => PharmacyRules::qty(),
        ];
    }
}
