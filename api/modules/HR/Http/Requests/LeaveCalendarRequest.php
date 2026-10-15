<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** GET hr/leave-calendar (HR-15): pending and approved leave over at most 92 days (this month by default). */
class LeaveCalendarRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'hr.leave.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'department' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'employee' => ['sometimes', 'uuid', Rule::exists('hr_employees', 'id')],
        ];
    }
}
