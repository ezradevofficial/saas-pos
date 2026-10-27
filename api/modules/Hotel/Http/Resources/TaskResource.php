<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\HousekeepingTask;

/**
 * HOT-28, HOT-29: a housekeeping task. Room and status only, never guest data.
 *
 * @mixin HousekeepingTask
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var HousekeepingTask $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'branch_id' => $t->branch_id,
            'room' => $t->relationLoaded('room') && $t->room !== null ? ['id' => $t->room->id, 'number' => $t->room->number, 'floor' => $t->room->floor, 'status' => $t->room->status] : ['id' => $t->room_id],
            'kind' => $t->kind,
            'status' => $t->status,
            'business_date' => Refs::day($t->business_date),
            'assigned_to' => $t->relationLoaded('assignee') ? Refs::user($t->assignee) : null,
            'started_at' => Refs::at($t->started_at),
            'finished_at' => Refs::at($t->finished_at),
            'notes' => $t->notes,
        ];
    }
}
