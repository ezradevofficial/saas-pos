<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Employee;
use Modules\HR\Models\Punch;

/** POST hr/punches/{hr_punch}/void (HR-22): `hr.attendance.manage` at the employee's place; a reason. */
class VoidPunchRequest extends HrRequest
{
    public function punch(): Punch
    {
        return $this->route('hr_punch');
    }

    public function authorize(): bool
    {
        return $this->onEmployee(Employee::query()->findOrFail($this->punch()->employee_id), 'hr.attendance.manage', 'hr.attendance.view');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
