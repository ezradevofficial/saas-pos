<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\TimeRule;

/** PATCH hr/time-rules/{hr_time_rule} (HR-20). */
class TimeRuleRequest extends SetupRowRequest
{
    public static function rulesFor(bool $creating): array
    {
        return [
            'effective_from' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'daily_overtime_after_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'weekly_overtime_after_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,10080'],
            'night_start' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:night_end'],
            'night_end' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:night_start'],
            'late_grace_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'early_leave_grace_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'rest_days' => ['sometimes', 'array', 'max:7'],
            'rest_days.*' => ['string', Rule::in(TimeRule::DAYS)],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_time_rule';
    }

    protected function viewPermission(): string
    {
        return 'hr.attendance.view';
    }

    protected function managePermission(): string
    {
        return 'hr.attendance.manage';
    }

    protected function fields(): array
    {
        return self::rulesFor(false);
    }
}
