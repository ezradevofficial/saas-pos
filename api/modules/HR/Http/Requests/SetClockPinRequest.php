<?php

namespace Modules\HR\Http\Requests;

/** PUT hr/employees/{hr_employee}/clock-pin (HR-23): `hr.attendance.manage` at the place; 4 to 6 digits. */
class SetClockPinRequest extends EmployeeGroupRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }

    public function rules(): array
    {
        return ['pin' => ['required', 'string', 'regex:/^\d{4,6}$/']];
    }
}
