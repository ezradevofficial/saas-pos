<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/nozzles (FUEL-07): `fuel.setup.view` at the reader's stations; `?location_id=`, `?pump_id=`, `?archived=1`. */
class ListNozzlesRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'pump_id' => ['sometimes', 'uuid'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
