<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Dip;

/** POST fuel/dips (FUEL-23, FUEL-24): `fuel.dip.record` at the station. `{tank_id, dip_cm, water_cm?, taken_at?, kind?}`, kind `routine` (default), `shift_open` or `shift_close`. */
class StoreDipRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.dip.record';
    }

    protected function bodyRecords(): array
    {
        return ['tank_id' => 'fuel_tanks'];
    }

    public function rules(): array
    {
        return [
            'tank_id' => FuelRules::id('fuel_tanks', true, true),
            'dip_cm' => FuelRules::cm(),
            'water_cm' => FuelRules::cm(false, true),
            'taken_at' => ['sometimes', 'nullable', 'date'],
            'kind' => ['sometimes', 'string', Rule::in(Dip::RECONCILING)],
        ];
    }
}
