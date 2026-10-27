<?php

namespace Modules\Pharmacy\Http\Requests;

/**
 * GET pharmacy/register/print (PHA-19): `pharmacy.register.print` at the
 * location; the register of one item at one place as a page of its own.
 * `?item_id=`, `?location_id=`, optional `?from=` and `?to=` (calendar days).
 */
class PrintRegisterRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.register.print';
    }

    protected function places(): array
    {
        return ['location_id' => 'pharmacy.register.print'];
    }

    public function rules(): array
    {
        return [
            'item_id' => PharmacyRules::item(),
            'location_id' => PharmacyRules::location(),
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
