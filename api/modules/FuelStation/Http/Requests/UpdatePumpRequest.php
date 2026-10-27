<?php

namespace Modules\FuelStation\Http\Requests;

/** PATCH fuel/pumps/{id} (FUEL-07): `fuel.setup.manage` at the station. `{name?}`. */
class UpdatePumpRequest extends FuelRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
