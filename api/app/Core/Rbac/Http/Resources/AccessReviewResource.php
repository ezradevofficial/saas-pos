<?php

namespace App\Core\Rbac\Http\Resources;

use App\Core\Rbac\Models\RoleAssignment;
use Illuminate\Http\Request;

/**
 * One access review row (RBAC-11): an assignment with its user. Load
 * `user`, `role` and `creator`.
 *
 * @mixin RoleAssignment
 */
class AccessReviewResource extends AssignmentResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'contact' => $this->user->email ?? $this->user->phone,
                'status' => $this->user->status,
            ],
            ...parent::toArray($request),
        ];
    }
}
