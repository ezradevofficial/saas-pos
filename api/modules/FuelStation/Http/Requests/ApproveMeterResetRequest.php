<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/meter-resets/{id}/approve (FUEL-17): `fuel.meter.approve_reset` at the station; never the requester's own. */
class ApproveMeterResetRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.meter.approve_reset';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_reset';
    }

    public function rules(): array
    {
        return [];
    }
}
