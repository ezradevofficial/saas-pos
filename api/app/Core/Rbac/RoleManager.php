<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RBAC-02, RBAC-10, RBAC-12: role and assignment changes, each audited,
 * guarded against privilege escalation and owner loss, and enforcing a
 * role's two-factor requirement on its holders' tokens (AUTH-03).
 */
class RoleManager
{
    public function __construct(
        private readonly Grants $grants,
        private readonly OwnerGuard $owners,
        private readonly RoleTemplates $templates,
        private readonly TwoFactor $twoFactor,
    ) {}

    /** @param array{name: string, description?: ?string, requires_two_factor?: bool, permissions?: list<string>} $data */
    public function create(User $actor, array $data): Role
    {
        $permissions = $data['permissions'] ?? [];
        $this->grants->assertHolds($actor, $permissions, Scope::tenant());

        return $this->uniqueName(fn () => $this->transaction(function () use ($data, $permissions) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => Permission::GUARD,
                'description' => $data['description'] ?? null,
                'requires_two_factor' => (bool) ($data['requires_two_factor'] ?? false),
            ]);

            return $role->setPermissions($permissions);
        }));
    }

    /**
     * Edit a custom role (403 `system_role` for system roles). Permissions
     * added must be held by $actor at tenant scope (403 `cannot_grant`).
     *
     * @param  array{name?: string, description?: ?string, requires_two_factor?: bool, permissions?: list<string>}  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $role->assertEditable();

        if (array_key_exists('permissions', $data)) {
            $added = array_values(array_diff($data['permissions'], $role->permissionNames()));
            $this->grants->assertHolds($actor, $added, Scope::tenant());
        }

        return $this->uniqueName(fn () => $this->transaction(function () use ($role, $data) {
            $startsRequiring = ($data['requires_two_factor'] ?? false) && ! $role->requires_two_factor;

            $role->fill(array_intersect_key($data, array_flip(['name', 'description', 'requires_two_factor'])))->save();

            if (array_key_exists('permissions', $data)) {
                $role->setPermissions($data['permissions']);
            }

            if ($startsRequiring) {
                $this->enforceTwoFactor(User::whereIn('id', $role->assignments()->select('user_id'))->get());
            }

            return $role;
        }));
    }

    /** An editable copy of $role; $actor must hold its permissions (no escalation). */
    public function copy(User $actor, Role $role, string $name): Role
    {
        $this->grants->assertHolds($actor, $role->permissionNames(), Scope::tenant());

        return $this->uniqueName(fn () => $this->templates->copy($role, $name));
    }

    /** Archive a custom role; its assignments stop granting (RBAC-10 for owner roles). */
    public function archive(Role $role): Role
    {
        $role->assertEditable();

        return $this->transaction(function () use ($role) {
            if ($role->is_owner) {
                $this->owners->assertRoleArchivable($role);
            }

            if (! $role->isArchived()) {
                $role->archive();
            }

            return $role;
        });
    }

    /** Grant $role at $scope to $user, as $actor (checked by Grants). */
    public function assign(User $actor, User $user, Role $role, Scope $scope): RoleAssignment
    {
        $this->grants->assertCanGrant($actor, $role, $scope);

        $duplicate = fn () => ValidationException::withMessages(['role_id' => __('rbac.errors.already_assigned')]);
        $exists = RoleAssignment::where('user_id', $user->id)->where('role_id', $role->id)
            ->where('scope_type', $scope->type)->where('scope_id', $scope->id)->exists();

        if ($exists) {
            throw $duplicate();
        }

        try {
            return $this->transaction(function () use ($actor, $user, $role, $scope) {
                $assignment = $this->createAssignment($user, $role, $scope, $actor->id);

                if ($role->requires_two_factor) {
                    $this->enforceTwoFactor([$user]);
                }

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw $duplicate();
        }
    }

    /** Create an assignment without checks (invitation accept checks first). */
    public function createAssignment(User $user, Role $role, Scope $scope, ?string $grantedBy): RoleAssignment
    {
        return RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'created_by' => $grantedBy,
        ]);
    }

    /** Remove an assignment, as $actor; the last Owner's stays (RBAC-10). */
    public function unassign(User $actor, RoleAssignment $assignment): void
    {
        $this->grants->assertCanRevoke($actor, $assignment);

        $this->transaction(function () use ($assignment) {
            $this->owners->assertAssignmentRemovable($assignment);
            $assignment->delete();
        });
    }

    /**
     * AUTH-03: holders of a role that requires two-factor and who have not
     * set it up keep only enrolment rights on their tokens.
     *
     * @param  iterable<User>  $users
     */
    private function enforceTwoFactor(iterable $users): void
    {
        foreach ($users as $user) {
            if (! $user->hasTwoFactor()) {
                $this->twoFactor->downgradeTokens($user);
            }
        }
    }

    /**
     * Run $fn, turning a lost race on the active-name index into a
     * validation error on `name`.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function uniqueName(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => 'name'])]);
        }
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
