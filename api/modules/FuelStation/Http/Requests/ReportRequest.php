<?php

namespace Modules\FuelStation\Http\Requests;

/**
 * GET fuel/reports/* (FUEL-34): `fuel.report.view` at the reader's stations.
 * `?from=`, `?to=` (days, default the last 30), `?location_id=`, and per
 * report `?attendant_id=`, `?nozzle_id=`, `?tank_id=`, `?customer_id=`.
 */
class ReportRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.report.view';
    }

    public function rules(): array
    {
        return [
            ...FuelRules::period(),
            'location_id' => ['sometimes', 'uuid'],
            'attendant_id' => ['sometimes', 'uuid'],
            'nozzle_id' => ['sometimes', 'uuid'],
            'tank_id' => ['sometimes', 'uuid'],
            'customer_id' => ['sometimes', 'uuid'],
            ...FuelRules::paging(),
        ];
    }
}
