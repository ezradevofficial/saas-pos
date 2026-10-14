<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\TimesheetList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Models\AttendanceDay;

/** GET hr/timesheets (HR-25): days at the reader's places; `?status=`, `?from=`, `?to=`. */
class ListTimesheetsRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new TimesheetList;
    }

    protected function viewPermission(): string
    {
        return 'hr.attendance.view';
    }

    protected function statuses(): array
    {
        return AttendanceDay::STATUSES;
    }
}
