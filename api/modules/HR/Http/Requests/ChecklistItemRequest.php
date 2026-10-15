<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Checklist;
use Modules\HR\Models\ChecklistItem;
use Modules\HR\Models\Employee;

/** POST hr/checklist-items/{hr_checklist_item}/complete|reopen (HR-28): `hr.checklist.manage` at the employee's place. */
class ChecklistItemRequest extends HrRequest
{
    public function item(): ChecklistItem
    {
        return $this->route('hr_checklist_item');
    }

    public function authorize(): bool
    {
        $employee = Employee::query()->findOrFail(Checklist::query()->whereKey($this->item()->checklist_id)->value('employee_id'));

        return $this->onEmployee($employee, 'hr.checklist.manage', 'hr.checklist.view');
    }

    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
