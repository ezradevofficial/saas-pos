<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/pumps (FUEL-07): `fuel.setup.view` at the reader's stations; `?location_id=`, `?archived=1`. Pumps carry their nozzles. */
class ListPumpsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
