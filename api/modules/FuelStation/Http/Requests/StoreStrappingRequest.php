<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/tanks/{id}/strapping (FUEL-06): `fuel.setup.manage` at the station. `{effective_from?, note?, points: [{dip_cm, litres}]}`. */
class StoreStrappingRequest extends FuelRequest
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
        return [
            'effective_from' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'points' => ['required', 'array', 'min:2', 'max:500'],
            'points.*.dip_cm' => FuelRules::cm(),
            'points.*.litres' => FuelRules::litres(),
        ];
    }
}
