<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveType;

/** PATCH hr/leave-types/{hr_leave_type} (with `confirm` to confirm statutory days, HR-12), POST …/archive. */
class LeaveTypeRequest extends SetupRowRequest
{
    public static function rulesFor(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:120'],
            'paid' => ['sometimes', 'boolean'],
            // CP-01: a paid type's share of pay as an exact fraction ("2/3"); null is full pay.
            'pay_share' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]?\/[1-9][0-9]?$/'],
            'accrual' => [$required, Rule::in(LeaveType::ACCRUALS)],
            'entitlement_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'count_basis' => [$required, Rule::in(LeaveType::COUNT_BASES)],
            'carry_over_max_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'allow_negative' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'accrues_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // HR-12: months of service first, half-pay days after the full-pay days, per-event days.
            'qualifying_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'half_pay_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'events' => ['sometimes', 'nullable', 'array', 'max:20'],
            'events.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'events.*.days' => ['required', 'integer', 'min:1', 'max:366'],
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
        return self::rulesFor(false);
    }
}
