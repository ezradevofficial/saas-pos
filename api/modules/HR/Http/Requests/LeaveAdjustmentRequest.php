<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/leave-adjustments (HR-13): `hr.leave.manage` at the employee's place; signed days. */
class LeaveAdjustmentRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.leave.manage';
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'leave_type_id' => ['required', 'uuid', Rule::exists('hr_leave_types', 'id')],
            'days' => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/', 'not_in:0,-0,0.0,0.00,-0.00'],
            'effective_on' => ['sometimes', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
