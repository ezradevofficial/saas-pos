<?php

namespace Modules\HR\Http\Requests;

/** POST hr/employees/{hr_employee}/clock-pin/clear (HR-23): `hr.attendance.manage` at the place. */
class ClearClockPinRequest extends EmployeeGroupRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }
}
