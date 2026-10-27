<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST pharmacy/register/adjustments (PHA-18): `pharmacy.register.adjust` at
 * the location; `{item_id, location_id, qty (signed, not zero), reason,
 * lot_id?}`. A correction is a new entry; nothing is ever edited. It
 * corrects the register's balance only, not the lot ledger (a lot's stock
 * is changed by a write-off or a receipt).
 */
class RegisterAdjustmentRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.register.adjust';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.register.adjust'];
    }

    public function rules(): array
    {
        return [
            'item_id' => PharmacyRules::item(),
            'location_id' => PharmacyRules::location(),
            'qty' => ['required', 'string', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/', 'not_regex:/^-?0+(\.0+)?$/'],
            'reason' => ['required', 'string', 'max:500'],
            'lot_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('pharmacy_lots', 'id')],
        ];
    }
}
