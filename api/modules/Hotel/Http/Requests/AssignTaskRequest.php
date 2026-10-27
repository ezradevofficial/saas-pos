<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hotel/housekeeping/tasks/{hotel_task}/assign (HOT-28): `hotel.housekeeping.assign` at the task's branch. `{assigned_to}` (null: unassign). */
class AssignTaskRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.assign';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_task')?->branch_id;
    }

    public function rules(): array
    {
        return ['assigned_to' => ['present', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')]];
    }
}
