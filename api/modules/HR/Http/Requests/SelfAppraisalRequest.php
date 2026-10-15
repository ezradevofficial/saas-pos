<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Appraisal;

/** PATCH hr/me/appraisals/{hr_appraisal}, POST …/acknowledge (HR-31, HR-30): the employee's own appraisal. */
class SelfAppraisalRequest extends SelfRequest
{
    public function appraisal(): Appraisal
    {
        return $this->route('hr_appraisal');
    }

    public function authorize(): bool
    {
        abort_unless($this->appraisal()->employee_id === $this->me()->id, 404);

        return true;
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? [
            'self_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'self_comments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'submit' => ['sometimes', 'boolean'],
        ] : [];
    }
}
