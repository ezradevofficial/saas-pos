<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fuel/reconciliations (FUEL-24): `fuel.variance.view` at the reader's stations; `?tank_id=`, `?status=`, `?location_id=`, `?from=`, `?to=`. */
class ListReconciliationsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tank_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(['baseline', 'accepted', 'pending_approval', 'approved', 'rejected'])],
            ...FuelRules::paging(),
        ];
    }
}
