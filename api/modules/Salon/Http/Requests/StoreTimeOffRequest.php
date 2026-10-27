<?php

namespace Modules\Salon\Http\Requests;

/** POST salon/staff/{id}/time-off (SAL-04): `salon.staff.manage` at the staff member's place. */
class StoreTimeOffRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'in:leave,sick,training,other'],
        ];
    }
}
