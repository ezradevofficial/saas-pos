<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-22: a punch. */
class PunchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => Refs::employee($this->employee),
            'kind' => $this->kind,
            'occurred_at' => Refs::at($this->occurred_at),
            'source' => $this->source,
            'device_id' => $this->device_id,
            'attendance_device_id' => $this->attendance_device_id,
            'location_id' => $this->location_id,
            'note' => $this->note,
            'flags' => $this->flags ?? [],
            'received_at' => Refs::at($this->received_at),
            'voided_at' => Refs::at($this->voided_at),
            'void_reason' => $this->void_reason,
        ];
    }
}
