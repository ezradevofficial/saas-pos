<?php

namespace App\Core\MasterData\Items\Http\Resources;

use App\Core\MasterData\Items\Uom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit of measure (MD-02).
 *
 * @mixin Uom
 */
class UomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => strtoupper((string) $this->code),
            'name' => $this->name,
            'kind' => $this->kind,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
