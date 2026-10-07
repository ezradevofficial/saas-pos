<?php

namespace App\Core\Rbac\Http\Resources;

use App\Core\Rbac\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role and its permission names, sorted (RBAC-02). Load `permissions`.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'template_key' => $this->template_key,
            'is_owner' => $this->is_owner,
            'requires_two_factor' => $this->requires_two_factor,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()->all()),
        ];
    }
}
