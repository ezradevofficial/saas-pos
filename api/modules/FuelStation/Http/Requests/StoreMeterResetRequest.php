<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fuel/meter-resets (FUEL-17): `fuel.meter.request_reset` at the station. `{nozzle_id, shift_id?, reading_before, reading_after, reason}`. */
class StoreMeterResetRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.meter.request_reset';
    }

    protected function bodyRecords(): array
    {
        return ['nozzle_id' => 'fuel_nozzles'];
    }

    public function rules(): array
    {
        return [
            'nozzle_id' => FuelRules::id('fuel_nozzles', true, true),
            'shift_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('fuel_shifts', 'id')],
            'reading_before' => FuelRules::litres(),
            'reading_after' => FuelRules::litres(),
            'reason' => FuelRules::reason(),
        ];
    }
}
