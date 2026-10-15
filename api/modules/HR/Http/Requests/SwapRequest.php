<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Employee;
use Modules\HR\Models\ShiftSwap;

/** GET hr/shift-swaps/{hr_shift_swap}, POST …/accept|decline|cancel (HR-19): `hr.roster.manage` at the swap's place to act. */
class SwapRequest extends HrRequest
{
    public function swap(): ShiftSwap
    {
        return $this->route('hr_shift_swap');
    }

    public function authorize(): bool
    {
        $scope = $this->swap()->scope();
        abort_unless($this->access()->at($this->user(), 'hr.roster.view', $scope) || $this->access()->at($this->user(), 'hr.roster.manage', $scope), 404);

        if ($this->isMethod('GET')) {
            return true;
        }

        // HR-19: the peer's answer is given for the peer: at the peer's place.
        if (str_ends_with($this->path(), '/accept') || str_ends_with($this->path(), '/decline')) {
            $peer = Employee::query()->findOrFail($this->swap()->peer_employee_id);

            return $this->access()->can($this->user(), 'hr.roster.manage', $peer);
        }

        return $this->access()->at($this->user(), 'hr.roster.manage', $scope);
    }
}
