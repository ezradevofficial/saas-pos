<?php

namespace App\Core\Identity\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Http\Resources\AssignmentResource;
use Illuminate\Http\Request;

/**
 * A user as administrators see it: the profile and, when loaded, the roles
 * held and where (RBAC-04).
 *
 * @mixin User
 */
class UserAdminResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'created_at' => $this->created_at?->toIso8601String(),
            'roles' => AssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
