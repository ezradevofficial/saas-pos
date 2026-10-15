<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-08: a position. */
class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company' => Refs::named($this->company),
            'code' => $this->code,
            'title' => $this->title,
            'department' => Refs::coded($this->department),
            'grade' => $this->grade,
            'archived_at' => Refs::at($this->archived_at),
        ];
    }
}
