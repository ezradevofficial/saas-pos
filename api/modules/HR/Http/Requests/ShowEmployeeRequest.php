<?php

namespace Modules\HR\Http\Requests;

/** GET hr/employees/{hr_employee} (HR-02): `hr.employee.view` at the employee's place; anything else is not found. */
class ShowEmployeeRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.employee.view');
    }
}
