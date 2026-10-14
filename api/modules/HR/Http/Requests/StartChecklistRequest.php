<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/employees/{hr_employee}/checklists `{template_id}` (HR-28): `hr.checklist.manage` at the place. */
class StartChecklistRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.checklist.manage');
    }

    public function rules(): array
    {
        return ['template_id' => ['required', 'uuid', Rule::exists('hr_checklist_templates', 'id')]];
    }
}
