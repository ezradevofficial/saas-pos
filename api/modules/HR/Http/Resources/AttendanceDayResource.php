<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\AttendanceDay;

/** HR-25: a timesheet day. */
class AttendanceDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'employee' => Refs::employee($this->employee),
            'work_date' => Refs::date($this->work_date),
            'roster_shift_id' => $this->roster_shift_id,
            'first_in' => Refs::at($this->first_in),
            'last_out' => Refs::at($this->last_out),
            'status' => $this->status,
            'exceptions' => $this->exceptions ?? [],
            'approved_at' => Refs::at($this->approved_at),
            'period_id' => $this->period_id,
        ];

        foreach (AttendanceDay::MINUTES as $minutes) {
            $data["{$minutes}_minutes"] = $this->{"{$minutes}_minutes"};
        }

        return $data;
    }
}
