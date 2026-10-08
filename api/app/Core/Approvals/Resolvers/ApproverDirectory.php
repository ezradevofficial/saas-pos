<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;

/**
 * Who holds which roles where, for approver resolution (APR-02). Reads run
 * under the tenant's row-level security; archived roles and inactive
 * users never count.
 *
 * A "manager" at a place is a user assigned there (exactly there, not
 * above it) a role carrying `core.approval.reassign`: the Owner, Admin and
 * Branch Manager templates carry it, and a tenant can give it to its own
 * roles. Managers are what `manager_levels_up` and next-level escalation
 * look for.
 */
class ApproverDirectory
{
    public const MANAGER_PERMISSION = 'core.approval.reassign';

    /**
     * Active users holding one of $roleIds at one of the exact places $scopes (`type:id`).
     *
     * @param  list<string>  $roleIds
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function holdersAt(array $roleIds, array $scopes): array
    {
        if ($roleIds === [] || $scopes === []) {
            return [];
        }

        $roleIds = Role::query()->whereKey($roleIds)->whereNull('archived_at')->pluck('id')->all();

        if ($roleIds === []) {
            return [];
        }

        return RoleAssignment::query()
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereIn('role_assignments.role_id', $roleIds)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where(function ($q) use ($scopes) {
                foreach ($scopes as $scope) {
                    [$type, $id] = explode(':', $scope, 2);
                    $q->orWhere(fn ($w) => $w->where('role_assignments.scope_type', $type)->where('role_assignments.scope_id', $id));
                }
            })
            ->toBase()
            ->distinct()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @return list<string> active roles carrying $permission */
    public function rolesWith(string $permission): array
    {
        return Role::query()->whereNull('archived_at')
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return list<string> managers assigned exactly at $scope */
    public function managersAt(string $scope): array
    {
        return $this->holdersAt($this->rolesWith(self::MANAGER_PERMISSION), [$scope]);
    }
}
