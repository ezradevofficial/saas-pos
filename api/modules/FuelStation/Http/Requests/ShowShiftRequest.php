<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/shifts/{id} (FUEL-09): `fuel.shift.view` at the station. */
class ShowShiftRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.shift.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_shift';
    }

    public function rules(): array
    {
        return [];
    }
}
