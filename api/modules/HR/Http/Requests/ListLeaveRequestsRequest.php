<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\LeaveRequestList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Models\LeaveRequest;

/** GET hr/leave-requests (HR-14, HR-35). */
class ListLeaveRequestsRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new LeaveRequestList;
    }

    protected function viewPermission(): string
    {
        return 'hr.leave.view';
    }

    protected function statuses(): array
    {
        return LeaveRequest::STATUSES;
    }
}
