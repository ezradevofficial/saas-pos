<?php

namespace Modules\HR\Http\Requests;

/** POST hr/me/leave-requests (HR-31, HR-14): the employee's own request. */
class SelfLeaveRequest extends SelfRequest
{
    public function rules(): array
    {
        return LeaveRequestRules::rules();
    }
}
