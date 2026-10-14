<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveType;

/** PATCH hr/leave-types/{hr_leave_type} (with `confirm` to confirm statutory days, HR-12), POST …/archive. */
class LeaveTypeRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:120'],
            'paid' => ['sometimes', 'boolean'],
            'accrual' => [$required, Rule::in(LeaveType::ACCRUALS)],
            'entitlement_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'count_basis' => [$required, Rule::in(LeaveType::COUNT_BASES)],
            'carry_over_max_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'allow_negative' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'accrues_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ...($creating ? [] : ['confirm' => ['sometimes', 'boolean']]),
        ];
    }

    protected function parameter(): string
    {
        return 'hr_leave_type';
    }

    protected function viewPermission(): string
    {
        return 'hr.leave.view';
    }

    protected function managePermission(): string
    {
        return 'hr.leave.manage';
    }

    protected function fields(): array
    {
        return self::FIELDS(false);
    }
}
