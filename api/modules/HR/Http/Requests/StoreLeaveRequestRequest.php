<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/leave-requests (HR-14): `hr.leave.request` at the employee's place (asking on their behalf). */
class StoreLeaveRequestRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.leave.request';
    }

    public function rules(): array
    {
        return ['employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')], ...LeaveRequestRules::rules()];
    }
}
