<?php

namespace App\Core\Rbac;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\Visibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who may grant what, where (RBAC-04, RBAC-10, no privilege escalation).
 *
 * An actor grants role R at scope S only when
 *  - S exists in the tenant at its level (404 otherwise) and is not
 *    archived (422 `parent_archived`), and the actor reaches S for
 *    `core.role.assign` (at, above or beneath it; 404 otherwise, so ids
 *    out of scope are never confirmed);
 *  - the actor holds `core.role.assign` at a scope covering S, and holds
 *    every permission of R at a scope covering S (403 `cannot_grant`);
 *  - R is an owner role only if the actor is an Owner (403 `cannot_grant`).
 *
 * Permissions of modules the tenant has not activated grant nothing to
 * anyone, so they are left out of the "holds every permission" check.
 */
class Grants
{
    public const ASSIGN = 'core.role.assign';

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Visibility $visibility,
        private readonly ModuleRegistry $modules,
        private readonly OwnerGuard $owners,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The scope named by $type and $id, checked to exist in the current
     * tenant (404) and, for a new grant, to be active (422).
     */
    public function scope(string $type, ?string $id, bool $active = true): Scope
    {
        $tenantId = $this->tenants->require();

        if ($type === Scope::TENANT) {
            abort_unless($id === null || strtolower($id) === $tenantId, 404);

            return Scope::tenant($tenantId);
        }

        $class = match ($type) {
            Scope::COMPANY => Company::class,
            Scope::BRANCH => Branch::class,
            Scope::LOCATION => Location::class,
            default => abort(404),
        };

        $node = $id !== null && Str::isUuid($id) ? $class::find($id) : null;
        abort_if($node === null, 404);

        if ($active && $node->isArchived()) {
            throw new ApiException(422, 'parent_archived', __('core.organisation.parent_archived'));
        }

        return Scope::of($type, $node->id);
    }

    /** A role of the current tenant (404 otherwise), active for a new grant (422). */
    public function role(?string $id, bool $active = true): Role
    {
        $role = $id !== null && Str::isUuid($id) ? Role::find($id) : null;
        abort_if($role === null, 404);

        if ($active && $role->isArchived()) {
            throw new ApiException(422, 'parent_archived', __('core.organisation.parent_archived'));
        }

        return $role;
    }

    /** May $actor grant $role at $scope? Throws 404 or 403 `cannot_grant`. */
    public function assertCanGrant(User $actor, Role $role, Scope $scope): void
    {
        $this->assertManagesScope($actor, $scope, $role);
        $this->assertHolds($actor, $this->permissionsOf($role), $scope);
    }

    /** May $actor remove $assignment? Throws 404 or 403 `cannot_grant`. */
    public function assertCanRevoke(User $actor, RoleAssignment $assignment): void
    {
        $this->assertManagesScope($actor, $assignment->scope(), $assignment->role);
    }

    /**
     * Refuse with 403 `cannot_grant` unless $actor holds every one of
     * $permissions (of active modules) at a scope covering $scope.
     *
     * @param  list<string>  $permissions
     */
    public function assertHolds(User $actor, array $permissions, Scope $scope): void
    {
        $active = array_flip($this->modules->active());
        $needed = array_filter($permissions, fn (string $name) => isset($active[PermissionRegistry::moduleOf($name)]));

        if ($needed === []) {
            return;
        }

        $roleIds = $this->resolver->roleIds($actor, $scope);
        $held = $roleIds === [] ? [] : DB::connection(TenantContext::CONNECTION)
            ->table(config('permission.table_names.role_has_permissions'))
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', 'permission_id')
            ->whereIn('role_id', $roleIds)
            ->where('p.guard_name', Permission::GUARD)
            ->distinct()
            ->pluck('p.name')
            ->all();

        if (array_diff($needed, $held) !== []) {
            throw self::cannotGrant();
        }
    }

    /** @return list<string> */
    private function permissionsOf(Role $role): array
    {
        return $role->permissions()->pluck('name')->all();
    }

    private function assertManagesScope(User $actor, Scope $scope, Role $role): void
    {
        if (! $scope->isTenant()) {
            $node = match ($scope->type) {
                Scope::COMPANY => Company::find($scope->id),
                Scope::BRANCH => Branch::find($scope->id),
                Scope::LOCATION => Location::find($scope->id),
            };

            abort_if($node === null || ! $this->visibility->reaches($actor, self::ASSIGN, $node), 404);
        }

        if (! $this->resolver->can($actor, self::ASSIGN, $scope)) {
            throw self::cannotGrant();
        }

        if ($role->is_owner && ! $this->owners->isOwner($actor)) {
            throw self::cannotGrant();
        }
    }

    public static function cannotGrant(): ApiException
    {
        return new ApiException(403, 'cannot_grant', __('rbac.errors.cannot_grant'));
    }
}
