<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/tanks/{id}/strapping (FUEL-06): `fuel.setup.view` at the station. */
class ListStrappingRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_tank';
    }

    public function rules(): array
    {
        return [
            ...FuelRules::paging(),
        ];
    }
}
