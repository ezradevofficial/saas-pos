<?php

namespace Modules\HR\Http\Requests;

/** POST hr/leave-types (HR-12): `hr.leave.manage` at the company. */
class StoreLeaveTypeRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.leave.manage';
    }

    protected function fields(): array
    {
        return LeaveTypeRequest::rulesFor(true);
    }
}
