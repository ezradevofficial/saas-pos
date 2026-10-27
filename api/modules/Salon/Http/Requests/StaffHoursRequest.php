<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/staff/{id}/hours (SAL-04): replaces the weekly spans; `salon.staff.manage` at the staff member's place. */
class StaffHoursRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:100'],
            'hours.*.branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'hours.*.weekday' => ['required', 'integer', 'between:1,7'],
            'hours.*.kind' => ['sometimes', 'required', 'string', 'in:work,break'],
            'hours.*.start' => ['required', 'date_format:H:i'],
            'hours.*.end' => ['required', 'date_format:H:i'],
        ];
    }
}
