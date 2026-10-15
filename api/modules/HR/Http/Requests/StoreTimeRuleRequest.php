<?php

namespace Modules\HR\Http\Requests;

/** POST hr/time-rules (HR-20): `hr.attendance.manage` at the company. */
class StoreTimeRuleRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.attendance.manage';
    }

    protected function fields(): array
    {
        return TimeRuleRequest::rulesFor(true);
    }
}
