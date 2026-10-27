<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/settlements/{id} (FUEL-13): `fuel.variance.view` at the station. */
class ShowSettlementRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_settlement';
    }

    public function rules(): array
    {
        return [];
    }
}
