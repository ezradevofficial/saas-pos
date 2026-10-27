<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/tanks/{id}/archive (FUEL-05, TEN-06): `fuel.setup.manage` at the station. */
class ArchiveTankRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_tank';
    }

    public function rules(): array
    {
        return [];
    }
}
