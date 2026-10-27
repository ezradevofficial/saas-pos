<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/branches/{branch}/online (SAL-15): `salon.online.manage` at the branch. */
class UpdateOnlineRequest extends BranchRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'required', 'boolean'],
            'public_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lead_minutes' => ['sometimes', 'required', 'integer', 'between:0,10080'],
            'max_days_ahead' => ['sometimes', 'required', 'integer', 'between:1,365'],
            'location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')],
        ];
    }
}
