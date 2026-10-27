<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/pumps/{id}/archive (FUEL-07, TEN-06): `fuel.setup.manage` at the station. */
class ArchivePumpRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_pump';
    }

    public function rules(): array
    {
        return [];
    }
}
