<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\HR\Models\Employee;

/**
 * A write naming an employee in its body (`employee_id`, checked under
 * RLS): the subclass's permission anywhere to try, and at the employee's
 * place to succeed (HR-38).
 */
abstract class EmployeeBodyRequest extends HrRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), $this->permission());
    }

    public function bodyEmployee(): Employee
    {
        return Employee::query()->findOrFail($this->input('employee_id'));
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->access()->can($this->user(), $this->permission(), $this->bodyEmployee())) {
                $this->forbid();
            }
        }];
    }
}
