<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\RosterShift;

/** PATCH hr/roster-shifts/{hr_roster_shift}, POST …/cancel (HR-18): `hr.roster.manage` at its place. */
class RosterShiftRequest extends HrRequest
{
    public function planned(): RosterShift
    {
        return $this->route('hr_roster_shift');
    }

    public function authorize(): bool
    {
        $scope = $this->planned()->scope();
        abort_unless($this->access()->at($this->user(), 'hr.roster.view', $scope) || $this->access()->at($this->user(), 'hr.roster.manage', $scope), 404);

        return $this->access()->at($this->user(), 'hr.roster.manage', $scope);
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'shift_id' => ['sometimes', 'uuid', Rule::exists('hr_shifts', 'id')],
        ] : [];
    }
}
