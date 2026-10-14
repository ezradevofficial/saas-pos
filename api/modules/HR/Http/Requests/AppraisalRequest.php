<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\Employee;

/**
 * GET/PATCH hr/appraisals/{hr_appraisal}, POST …/complete (HR-30): seen
 * with `hr.appraisal.view` at the employee's place or by its reviewer;
 * the review is written by the reviewer or `hr.appraisal.manage`.
 */
class AppraisalRequest extends HrRequest
{
    public function appraisal(): Appraisal
    {
        return $this->route('hr_appraisal');
    }

    public function authorize(): bool
    {
        $employee = Employee::query()->findOrFail($this->appraisal()->employee_id);
        $reviewer = $this->appraisal()->reviewer_id === $this->user()->id;
        abort_unless($reviewer || $this->access()->can($this->user(), 'hr.appraisal.view', $employee) || $this->access()->can($this->user(), 'hr.appraisal.manage', $employee), 404);

        return $this->isMethod('GET') || $reviewer || $this->access()->can($this->user(), 'hr.appraisal.manage', $employee);
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? [
            'goals' => ['sometimes', 'array', 'max:20'],
            'goals.*.title' => ['required', 'string', 'max:200'],
            'goals.*.weight' => ['sometimes', 'numeric', 'between:0,100'],
            'goals.*.rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'goals.*.comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'manager_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'manager_comments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reviewer_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
        ] : [];
    }
}
