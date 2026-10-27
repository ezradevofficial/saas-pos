<?php

namespace Modules\Hotel\Http\Requests;

use Modules\Hotel\Support\Access;

/** POST hotel/housekeeping/tasks/{hotel_task}/start and /finish (HOT-28, HOT-29): `hotel.housekeeping.update` at the task's branch; a housekeeper works their own tasks and unassigned ones. */
class UpdateTaskRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.update';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_task')?->branch_id;
    }

    public function authorize(): bool
    {
        $task = $this->bound('hotel_task');

        return parent::authorize() && ($task->assigned_to === null || $task->assigned_to === $this->user()->id || app(Access::class)->can($this->user(), 'hotel.housekeeping.assign', $task->branch_id));
    }

    public function rules(): array
    {
        return [];
    }
}
