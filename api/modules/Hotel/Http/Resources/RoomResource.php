<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Room;

/**
 * HOT-04, HOT-05: a room and its status.
 *
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Room $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'branch' => Refs::place($r->relationLoaded('branch') ? $r->branch : null) ?? ['id' => $r->branch_id, 'name' => null],
            'room_type' => $r->relationLoaded('roomType') && $r->roomType !== null ? ['id' => $r->roomType->id, 'code' => $r->roomType->code, 'name' => $r->roomType->name] : ['id' => $r->room_type_id],
            'number' => $r->number,
            'floor' => $r->floor,
            'features' => array_values($r->features ?? []),
            'notes' => $r->notes,
            'status' => $r->status,
            'out_of_order' => $r->status === Room::OUT_OF_ORDER ? ['reason' => $r->ooo_reason, 'from' => Refs::day($r->ooo_from), 'until' => Refs::day($r->ooo_until)] : null,
            'status_changed_at' => Refs::at($r->status_changed_at),
            'archived_at' => Refs::at($r->archived_at),
        ];
    }
}
