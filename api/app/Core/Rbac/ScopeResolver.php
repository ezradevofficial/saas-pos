<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Answers "may this user do X here?" (RBAC-04, RBAC-08, RBAC-09).
 *
 * A user holds roles at scopes (role_assignments). Which roles carry a
 * permission comes from Spatie's permission cache, keyed per tenant. A
 * tenant assignment covers everything; a company covers itself, its
 * branches and their locations; a branch covers itself and its locations; a
 * location covers itself. Archived roles, inactive users and permissions of
 * inactive modules grant nothing.
 *
 * Archiving a company, branch or location does not change coverage:
 * assignments there keep granting, so archived records can still be viewed
 * and restored (TEN-06). Hiding archived records is the job of list
 * queries, not of permissions.
 */
class ScopeResolver
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PermissionRegistrar $registrar,
        private readonly PermissionRegistry $catalogue,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @var array<string, true> permission names found missing after a reload, per cache key */
    private array $missing = [];

    /** True when $user holds $permission at $target, or anywhere when $target is null. */
    public function can(User $user, string $permission, ?Scope $target = null): bool
    {
        $assignments = $this->assignmentsWith($user, $permission);

        if ($assignments->isEmpty()) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        $chain = $this->chain($target);

        if ($chain === null) {
            return false;
        }

        return $assignments->contains(
            fn (object $a) => $a->scope_type === Scope::TENANT || in_array("{$a->scope_type}:{$a->scope_id}", $chain, true),
        );
    }

    /**
     * Ids of the active roles $user holds at a scope covering $target (any
     * scope when null), whatever their permissions. Used by field and limit
     * rules (RBAC-05, RBAC-06).
     *
     * @return list<string>
     */
    public function roleIds(User $user, ?Scope $target = null): array
    {
        if (! $this->eligible($user)) {
            return [];
        }

        $assignments = $this->assignments($user);

        if ($target !== null) {
            $chain = $this->chain($target) ?? [];
            $assignments = $assignments->filter(
                fn (object $a) => $chain !== [] && ($a->scope_type === Scope::TENANT || in_array("{$a->scope_type}:{$a->scope_id}", $chain, true)),
            );
        }

        return $assignments->pluck('role_id')->unique()->values()->all();
    }

    /**
     * Where $user holds $permission, expanded downwards (RBAC-04: lists,
     * search, reports and exports filter with it).
     */
    public function visibleIds(User $user, string $permission): VisibleScope
    {
        $assignments = $this->assignmentsWith($user, $permission);

        if ($assignments->contains('scope_type', Scope::TENANT)) {
            return new VisibleScope(all: true);
        }

        $ids = fn (string $type) => $assignments->where('scope_type', $type)->pluck('scope_id')->unique()->values()->all();

        $companyIds = $ids(Scope::COMPANY) === [] ? [] : Company::whereKey($ids(Scope::COMPANY))->orderBy('id')->pluck('id')->all();

        $branchIds = $this->union(
            $ids(Scope::BRANCH) === [] ? [] : Branch::whereKey($ids(Scope::BRANCH))->pluck('id')->all(),
            $companyIds === [] ? [] : Branch::whereIn('company_id', $companyIds)->pluck('id')->all(),
        );

        $locationIds = $this->union(
            $ids(Scope::LOCATION) === [] ? [] : Location::whereKey($ids(Scope::LOCATION))->pluck('id')->all(),
            $branchIds === [] ? [] : Location::whereIn('branch_id', $branchIds)->pluck('id')->all(),
        );

        return new VisibleScope(false, $companyIds, $branchIds, $locationIds);
    }

    /**
     * Every permission $user holds, with the scopes it is held at, for
     * permissions of active modules (RBAC-09: drives the UI).
     *
     * @return list<array{name: string, scopes: list<array{type: string, id: string}>}>
     */
    public function permissionsOf(User $user): array
    {
        if (! $this->eligible($user)) {
            return [];
        }

        $assignments = $this->assignments($user)->groupBy('role_id');

        if ($assignments->isEmpty()) {
            return [];
        }

        $active = array_flip($this->modules->active());
        $result = [];

        foreach ($this->registrar->getPermissions(['guard_name' => Permission::GUARD]) as $permission) {
            if (! isset($active[PermissionRegistry::moduleOf($permission->name)])) {
                continue;
            }

            $scopes = $permission->roles->modelKeys();
            $scopes = collect($scopes)
                ->flatMap(fn ($roleId) => $assignments->get($roleId, []))
                ->map(fn (object $a) => ['type' => $a->scope_type, 'id' => $a->scope_id])
                ->unique(fn (array $s) => $s['type'].':'.$s['id'])
                ->sortBy(fn (array $s) => array_search($s['type'], Scope::TYPES, true).':'.$s['id'])
                ->values()
                ->all();

            if ($scopes !== []) {
                $result[] = ['name' => $permission->name, 'scopes' => $scopes];
            }
        }

        usort($result, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * The user's assignments whose (active) role carries $permission, when
     * the user and the permission's module are active.
     *
     * @return Collection<int, object{role_id: string, scope_type: string, scope_id: string}>
     */
    private function assignmentsWith(User $user, string $permission): Collection
    {
        if (! $this->eligible($user) || ! $this->modules->isActive(PermissionRegistry::moduleOf($permission))) {
            return collect();
        }

        $roleIds = $this->rolesWith($permission);

        if ($roleIds === []) {
            return collect();
        }

        return $this->assignments($user)->whereIn('role_id', $roleIds)->values();
    }

    /** @return Collection<int, object{role_id: string, scope_type: string, scope_id: string}> */
    private function assignments(User $user): Collection
    {
        return RoleAssignment::query()
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereNull('roles.archived_at')
            ->where('role_assignments.user_id', $user->getKey())
            ->toBase()
            ->get(['role_assignments.role_id', 'role_assignments.scope_type', 'role_assignments.scope_id']);
    }

    /**
     * Ids of the current tenant's roles carrying $permission, from the
     * per-tenant cache. A name the code registers but the cache lacks (the
     * catalogue grew since it was built) reloads the cache once; a name
     * still missing after that (not synced yet) is remembered, so it cannot
     * rebuild the cache on every check.
     *
     * @return list<string>
     */
    private function rolesWith(string $permission): array
    {
        $find = fn () => $this->registrar
            ->getPermissions(['name' => $permission, 'guard_name' => Permission::GUARD], true)
            ->first();

        $found = $find();
        $missKey = $this->registrar->cacheKey.'|'.$permission;

        if ($found === null && $this->catalogue->has($permission) && ! isset($this->missing[$missKey])) {
            $this->registrar->forgetCachedPermissions();
            $found = $find();

            if ($found === null) {
                $this->missing[$missKey] = true;
            }
        }

        return $found === null ? [] : $found->roles->modelKeys();
    }

    /** Active, and a member of the tenant in context. */
    private function eligible(User $user): bool
    {
        $tenantId = $this->tenants->id();

        return $tenantId !== null && $user->tenant_id === $tenantId && $user->isActive();
    }

    /**
     * `type:id` of $target and every scope above it (its branch and company
     * read from the database), or null when the target is not in the
     * current tenant. Used to list who holds a role at a place (WF-10).
     *
     * @return list<string>|null
     */
    public function chainOf(Scope $target): ?array
    {
        return $this->chain($target);
    }

    /**
     * `type:id` of $target and every scope above it, or null when the target
     * is not in the current tenant.
     *
     * @return list<string>|null
     */
    private function chain(Scope $target): ?array
    {
        $tenantId = $this->tenants->id();

        if ($target->isTenant()) {
            return $target->id === null || $target->id === $tenantId ? ['tenant:'.$tenantId] : null;
        }

        if (! Str::isUuid($target->id)) {
            return null;
        }

        $db = DB::connection(TenantContext::CONNECTION);

        $row = match ($target->type) {
            Scope::COMPANY => $db->table('companies')->where('id', $target->id)
                ->first(['id as company_id']),
            Scope::BRANCH => $db->table('branches')->where('id', $target->id)
                ->first(['id as branch_id', 'company_id']),
            Scope::LOCATION => $db->table('locations')
                ->join('branches', 'branches.id', '=', 'locations.branch_id')
                ->where('locations.id', $target->id)
                ->first(['locations.id as location_id', 'locations.branch_id', 'branches.company_id']),
        };

        if ($row === null) {
            return null;
        }

        $chain = ['tenant:'.$tenantId];

        foreach ([Scope::COMPANY, Scope::BRANCH, Scope::LOCATION] as $type) {
            if (isset($row->{"{$type}_id"})) {
                $chain[] = "{$type}:{$row->{"{$type}_id"}}";
            }
        }

        return $chain;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function union(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}
