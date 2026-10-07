<?php

namespace App\Core\Rbac\Http\Resources;

use App\Core\Rbac\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role held at a scope, with the scope's name (ScopeNames::attach) and
 * who granted it when (RBAC-04, RBAC-11). Load `role` and `creator`.
 *
 * @mixin RoleAssignment
 */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'role' => [
                'id' => $this->role_id,
                'name' => $this->role?->name,
                'is_owner' => (bool) $this->role?->is_owner,
            ],
            'scope' => ['type' => $this->scope_type, 'id' => $this->scope_id, 'name' => $this->resource->scopeName],
            'granted_by' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name],
            'granted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
