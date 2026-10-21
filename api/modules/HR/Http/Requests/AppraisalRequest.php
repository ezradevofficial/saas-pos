<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;

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

        if ($this->isMethod('GET')) {
            return true;
        }

        $manages = $this->access()->can($this->user(), 'hr.appraisal.manage', $employee);

        // HR-30: only HR (hr.appraisal.manage) chooses the reviewer (a body field of the PATCH).
        $body = $this->json()->all();
        if (array_key_exists('reviewer_id', $body) && $body['reviewer_id'] !== $this->appraisal()->reviewer_id) {
            return $manages;
        }

        return $reviewer || $manages;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // The reviewer is never the appraised employee's own user.
            $own = Employee::query()->whereKey($this->appraisal()->employee_id)->value('user_id');

            $reviewer = $validator->getData()['reviewer_id'] ?? null;
            if ($validator->errors()->isEmpty() && $reviewer !== null && $own !== null && $reviewer === $own) {
                throw Errors::make(422, 'reviewer_is_employee', field: 'reviewer_id');
            }
        }];
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
            'reviewer_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
        ] : [];
    }
}
