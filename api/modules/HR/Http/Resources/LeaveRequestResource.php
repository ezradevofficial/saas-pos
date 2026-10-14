<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-14: a leave request. */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'employee' => Refs::employee($this->employee),
            'company_id' => $this->company_id,
            'leave_type' => Refs::coded($this->leaveType),
            'start_date' => Refs::date($this->start_date),
            'end_date' => Refs::date($this->end_date),
            'start_half' => $this->start_half,
            'end_half' => $this->end_half,
            'days' => (string) $this->days,
            'dates' => $this->dates,
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => Refs::user($this->requester),
            'decided_at' => Refs::at($this->decided_at),
            'cancelled_at' => Refs::at($this->cancelled_at),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => Refs::at($this->created_at),
        ];
    }
}
