<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/stations (FUEL-02): `fuel.setup.view` at the reader's stations; `?archived=1`. */
class ListStationsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    public function rules(): array
    {
        return [
            'archived' => ['sometimes', 'boolean'],
            'company_id' => ['sometimes', 'uuid'],
            ...FuelRules::paging(),
        ];
    }
}
