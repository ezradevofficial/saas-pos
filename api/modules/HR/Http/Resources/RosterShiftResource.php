<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-17, HR-18: a planned shift. */
class RosterShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => Refs::employee($this->employee),
            'date' => Refs::date($this->date),
            'shift' => Refs::coded($this->shift),
            'start_at' => Refs::at($this->start_at),
            'end_at' => Refs::at($this->end_at),
            'break_minutes' => $this->break_minutes,
            'source' => $this->source,
            'roster_id' => $this->roster_id,
            'status' => $this->status,
            'holiday' => (bool) ($this->holiday ?? false),
        ];
    }
}
