<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;

/**
 * GET hr/leave-requests/{hr_leave_request} and POST …/cancel (HR-14):
 * `hr.leave.view` at the request's place to see it (the employee's own
 * user too); cancelling needs `hr.leave.request` there, or being the
 * employee.
 */
class LeaveRequestRequest extends HrRequest
{
    public function leaveRequest(): LeaveRequest
    {
        return $this->route('hr_leave_request');
    }

    private function isOwn(): bool
    {
        $userId = Employee::query()->whereKey($this->leaveRequest()->employee_id)->where('status', Employee::ACTIVE)->value('user_id');

        return $userId !== null && $userId === $this->user()->id;
    }

    public function authorize(): bool
    {
        $request = $this->leaveRequest();
        $own = $this->isOwn();
        abort_unless($own || $this->access()->at($this->user(), 'hr.leave.view', $request->scope()) || $this->access()->at($this->user(), 'hr.leave.request', $request->scope()), 404);

        return $this->isMethod('GET') || $own || $this->access()->at($this->user(), 'hr.leave.request', $request->scope());
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : ['reason' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
