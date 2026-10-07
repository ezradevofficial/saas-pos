<?php

namespace App\Core\Rbac;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;

/**
 * Owner safety (RBAC-10): a tenant always keeps at least one active Owner,
 * that is an active user holding an unarchived owner role at tenant scope.
 * Called before removing an assignment, deactivating a user or archiving a
 * role (Task 10 wires the endpoints).
 */
class OwnerGuard
{
    /** @return list<string> ids of the current tenant's active Owners */
    public function ownerIds(): array
    {
        return RoleAssignment::query()
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->where('roles.is_owner', true)
            ->whereNull('roles.archived_at')
            ->where('role_assignments.scope_type', Scope::TENANT)
            ->where('users.status', User::STATUS_ACTIVE)
            ->distinct()
            ->pluck('role_assignments.user_id')
            ->all();
    }

    public function isOwner(User $user): bool
    {
        return in_array($user->getKey(), $this->ownerIds(), true);
    }

    /**
     * Refuse a change that would leave no active Owner once $target stops
     * being one: 422 `last_owner`.
     */
    public function assertNotLastOwner(User $target): void
    {
        $owners = $this->ownerIds();

        if (in_array($target->getKey(), $owners, true) && count($owners) === 1) {
            throw new ApiException(422, 'last_owner', __('rbac.errors.last_owner'));
        }
    }
}
