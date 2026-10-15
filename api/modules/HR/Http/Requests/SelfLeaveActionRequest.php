<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\LeaveRequest;

/** POST hr/me/leave-requests/{hr_leave_request}/cancel (HR-31): the employee's own request; anyone else's is not found. */
class SelfLeaveActionRequest extends SelfRequest
{
    public function leaveRequest(): LeaveRequest
    {
        return $this->route('hr_leave_request');
    }

    public function authorize(): bool
    {
        abort_unless($this->leaveRequest()->employee_id === $this->me()->id, 404);

        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
