<?php

namespace Modules\HR\Http\Requests;

/** POST hr/attendance-periods (HR-26): `hr.attendance.manage` at the company; at most 93 days. */
class StorePeriodRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }

    protected function fields(): array
    {
        return ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']];
    }
}
