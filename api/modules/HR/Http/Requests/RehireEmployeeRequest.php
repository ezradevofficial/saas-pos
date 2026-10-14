<?php

namespace Modules\HR\Http\Requests;

/** POST hr/employees/{hr_employee}/rehire (HR-05): `hr.employee.terminate` at the place. */
class RehireEmployeeRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.employee.terminate');
    }

    public function rules(): array
    {
        return ['hire_date' => ['required', 'date_format:Y-m-d']];
    }
}
