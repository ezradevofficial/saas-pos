<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST pharmacy/receipts (PHA-06): `pharmacy.lot.receive` at the location;
 * `{location_id, supplier_id?, reference?, received_at?, note?, lines: [{item_id,
 * uom_id?, qty, batch_number, expires_on, manufactured_on?, unit_cost_minor
 * (digits, minor units per entered unit), currency}]}`. A manufacture date
 * cannot be after the expiry; the expiry cannot already be past (checked in
 * the company's day by the service).
 */
class StoreReceiptRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.lot.receive';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.lot.receive'];
    }

    public function rules(): array
    {
        return [
            'location_id' => PharmacyRules::location(),
            'supplier_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'received_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => PharmacyRules::item(),
            'lines.*.uom_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            'lines.*.qty' => PharmacyRules::qty(),
            'lines.*.batch_number' => ['required', 'string', 'max:60'],
            'lines.*.expires_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.manufactured_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:lines.*.expires_on'],
            'lines.*.unit_cost_minor' => ['required', 'string', 'regex:/^\d{1,18}$/'],
            'lines.*.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('currencies', 'code')],
        ];
    }
}
