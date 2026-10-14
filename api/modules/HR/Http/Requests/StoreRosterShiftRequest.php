<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/roster-shifts (HR-18): `hr.roster.manage` at the employee's place. */
class StoreRosterShiftRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.roster.manage';
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_id' => ['required', 'uuid', Rule::exists('hr_shifts', 'id')],
        ];
    }
}
