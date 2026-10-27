<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fuel/deliveries (FUEL-25): `fuel.delivery.record` at the station. `{tank_id, supplier_id?, note_number, note_litres, before_dip_cm, after_dip_cm, before_taken_at?, after_taken_at?, unit_cost_minor, currency}`. */
class StoreDeliveryRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.delivery.record';
    }

    protected function bodyRecords(): array
    {
        return ['tank_id' => 'fuel_tanks'];
    }

    public function rules(): array
    {
        return [
            'tank_id' => FuelRules::id('fuel_tanks', true, true),
            'supplier_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'note_number' => ['required', 'string', 'max:60'],
            'note_litres' => FuelRules::litres(),
            'before_dip_cm' => FuelRules::cm(),
            'after_dip_cm' => FuelRules::cm(),
            'before_taken_at' => ['sometimes', 'nullable', 'date'],
            'after_taken_at' => ['sometimes', 'nullable', 'date'],
            'unit_cost_minor' => FuelRules::minor(),
            'currency' => FuelRules::currency(),
        ];
    }
}
