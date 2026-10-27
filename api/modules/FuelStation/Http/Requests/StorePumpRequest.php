<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/pumps (FUEL-07): `fuel.setup.manage` at the station. `{location_id, code, name}`. */
class StorePumpRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function locationField(): ?string
    {
        return 'location_id';
    }

    public function rules(): array
    {
        return [
            'location_id' => FuelRules::station(),
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
