<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/tanks/{id}/dip-convert?dip_cm=&at= (FUEL-06): `fuel.setup.view` at the station: the litres of a dip by the chart in force; without `dip_cm` the range the chart covers. */
class DipConvertRequest extends FuelRequest
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
            'dip_cm' => FuelRules::cm(false),
            'at' => ['sometimes', 'date'],
        ];
    }
}
