<?php

namespace App\Core\Tenancy\Http\Resources;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            // The parent's name, so a branch-scoped user can label the tree.
            'company' => ['id' => $this->company_id, 'name' => $this->company?->name],
            'name' => $this->name,
            'code' => $this->code,
            'timezone' => $this->timezone,
            'address' => (object) ($this->address ?? []),
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
