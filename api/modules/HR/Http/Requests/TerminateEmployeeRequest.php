<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\Employee;

/** POST hr/employees/{hr_employee}/terminate (HR-05): `hr.employee.terminate` at the place. */
class TerminateEmployeeRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.employee.terminate');
    }

    public function rules(): array
    {
        return [
            'termination_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(Employee::TERMINATION_REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
