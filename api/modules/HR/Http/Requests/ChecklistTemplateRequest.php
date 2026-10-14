<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** PATCH hr/checklist-templates/{hr_checklist_template}, POST …/archive (HR-28). */
class ChecklistTemplateRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            ...($creating ? ['kind' => ['required', Rule::in(['onboarding', 'offboarding'])]] : []),
            'name' => [$required, 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'items' => [$required, 'array', 'min:1', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.due_days' => ['sometimes', 'nullable', 'integer', 'between:-365,365'],
            'items.*.role_key' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_checklist_template';
    }

    protected function viewPermission(): string
    {
        return 'hr.checklist.view';
    }

    protected function managePermission(): string
    {
        return 'hr.checklist.manage';
    }

    protected function fields(): array
    {
        return self::FIELDS(false);
    }
}
