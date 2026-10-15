<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/punches (HR-22): a manual punch, `hr.attendance.manage` at the employee's place; a note is required. */
class StorePunchRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'kind' => ['required', Rule::in(['in', 'out'])],
            'occurred_at' => ['required', 'date', self::INSTANT, 'before_or_equal:'.now()->addHour()->toIso8601String()],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
