<?php

namespace App\Core\Tenancy\Http\Resources;

use App\Core\Tenancy\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => ['id' => $this->branch_id, 'name' => $this->branch?->name],
            'name' => $this->name,
            'type' => $this->type,
            'code' => $this->code,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
