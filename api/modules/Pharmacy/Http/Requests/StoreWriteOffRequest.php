<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Pharmacy\Models\WriteOff;

/**
 * POST pharmacy/write-offs (PHA-12): `pharmacy.writeoff.create` at the
 * location; `{location_id, reason, note?, lines: [{lot_id, qty}]}` (qty in the
 * item's base unit). The flow starts at once; stock leaves when it is approved.
 */
class StoreWriteOffRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.writeoff.create';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.writeoff.create'];
    }

    public function rules(): array
    {
        return [
            'location_id' => PharmacyRules::location(),
            'reason' => ['required', 'string', Rule::in(WriteOff::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.lot_id' => PharmacyRules::lot(),
            'lines.*.qty' => PharmacyRules::qty(),
        ];
    }
}
