<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/housekeeping/tasks/{hotel_task}/inspect (HOT-28): `hotel.housekeeping.assign` (the supervisor) at the task's branch. */
class InspectTaskRequest extends HotelRequest
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
        return [];
    }
}
