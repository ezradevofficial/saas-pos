<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HR\Support\Sensitive;

/** POST hr/disciplinary-cases (HR-29): `hr.disciplinary.manage` at the employee's place, `disciplinary` not locked. */
class StoreDisciplinaryRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.disciplinary.manage';
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            ...DisciplinaryRequest::FIELDS(true),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->sensitive()->mayEdit($this->user(), $this->bodyEmployee(), Sensitive::DISCIPLINARY)) {
                $this->forbid();
            }
        }];
    }
}
