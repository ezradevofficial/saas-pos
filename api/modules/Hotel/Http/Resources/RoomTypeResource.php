<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\RoomType;

/**
 * HOT-03: a room type.
 *
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RoomType $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'company_id' => $t->company_id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'base_adults' => $t->base_adults,
            'max_adults' => $t->max_adults,
            'max_children' => $t->max_children,
            'max_occupancy' => $t->max_occupancy,
            'beds' => $t->beds,
            'sort' => $t->sort,
            'rooms_count' => $t->rooms_count ?? null,
            'archived_at' => Refs::at($t->archived_at),
        ];
    }
}
