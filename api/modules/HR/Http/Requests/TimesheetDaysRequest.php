<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HR\Models\AttendanceDay;

/** POST hr/timesheets/approve|reopen `{day_ids}` (HR-25): `hr.attendance.manage` at every day's place. */
class TimesheetDaysRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'hr.attendance.manage');
    }

    public function rules(): array
    {
        return [
            'day_ids' => ['required', 'array', 'min:1', 'max:500'],
            'day_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('hr_attendance_days', 'id')],
        ];
    }

    /** @return Collection<int, AttendanceDay> */
    public function days(): Collection
    {
        return AttendanceDay::query()->whereKey($this->validated('day_ids'))->get();
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->days() as $day) {
                if (! $this->access()->at($this->user(), 'hr.attendance.manage', $day->scope())) {
                    $this->forbid();
                }
            }
        }];
    }
}
