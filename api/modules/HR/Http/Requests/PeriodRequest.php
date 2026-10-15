<?php

namespace Modules\HR\Http\Requests;

/**
 * GET hr/attendance-periods/{hr_attendance_period}, POST …/close (HR-26):
 * closing needs `hr.attendance.close` at the company; `force` with a
 * `reason` closes inside the tills' offline window (decision 25).
 */
class PeriodRequest extends SetupRowRequest
{
    protected function parameter(): string
    {
        return 'hr_attendance_period';
    }

    protected function viewPermission(): string
    {
        return 'hr.attendance.view';
    }

    protected function managePermission(): string
    {
        return 'hr.attendance.close';
    }

    protected function actionFields(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'required_if_accepted:force', 'string', 'max:500'],
        ];
    }
}
