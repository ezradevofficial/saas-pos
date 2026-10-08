<?php

namespace App\Core\Sync;

use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-06, AUTH-07, RBAC-04: who works the tills of a device's location.
 * A staff member is an active user holding an active role at a scope
 * covering the location (the tenant, its company, its branch or the
 * location itself) that carries at least one till permission (names
 * starting with a SyncSources till prefix, `pos.` by default) of an
 * active module (RBAC-08). Each comes with those permissions, the limit
 * rules of their covering roles (RBAC-06, the highest value per key) and
 * their field rules for parties and items (RBAC-05, computed as FieldRules
 * does: a field is hidden only when every role the user holds hides it).
 *
 * Bulk queries, a fixed number of them whatever the number of staff.
 */
class StaffDirectory
{
    /** Field-rule resources the till applies to its signed-in cashier. */
    public const FIELD_RESOURCES = ['item', 'party'];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * By user id, ordered by name.
     *
     * @return array<string, array{id: string, name: string, role_ids: list<string>, permissions: list<string>}>
     */
    public function at(DeviceScope $scope, ?string $onlyUserId = null): array
    {
        $db = DB::connection(TenantContext::CONNECTION);

        $assignments = $db->table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereNull('roles.archived_at')
            ->where('users.status', 'active')
            ->where(fn (Builder $q) => $this->covering($q, $scope))
            ->when($onlyUserId !== null, fn (Builder $q) => $q->where('role_assignments.user_id', $onlyUserId))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get(['role_assignments.user_id', 'role_assignments.role_id', 'users.name']);

        if ($assignments->isEmpty()) {
            return [];
        }

        $roleIds = $assignments->pluck('role_id')->unique()->values()->all();
        // Resolved here: SyncSources builds the staff source, which needs this class.
        $prefixes = app(SyncSources::class)->tillPermissionPrefixes();

        $permissions = $db->table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('role_has_permissions.role_id', $roleIds)
            ->whereIn('permissions.module', $this->modules->active())
            ->where(function (Builder $q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('permissions.name', 'like', addcslashes($prefix, '\\%_').'%');
                }
            })
            ->get(['role_has_permissions.role_id', 'permissions.name'])
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());

        $staff = [];

        foreach ($assignments as $assignment) {
            $staff[$assignment->user_id] ??= ['id' => $assignment->user_id, 'name' => $assignment->name, 'role_ids' => [], 'permissions' => []];
            $staff[$assignment->user_id]['role_ids'][] = $assignment->role_id;
            array_push($staff[$assignment->user_id]['permissions'], ...$permissions->get($assignment->role_id, []));
        }

        foreach ($staff as $id => $member) {
            if ($member['permissions'] === []) {
                unset($staff[$id]);

                continue;
            }

            $permissionsOf = array_values(array_unique($member['permissions']));
            sort($permissionsOf);
            $staff[$id]['permissions'] = $permissionsOf;
            $staff[$id]['role_ids'] = array_values(array_unique($member['role_ids']));
        }

        return $staff;
    }

    /**
     * RBAC-06: the highest value of each limit key across each user's
     * covering roles, as decimal strings (4 places); keys without a rule
     * are left out ("not allowed").
     *
     * @param  array<string, array{role_ids: list<string>}>  $staff
     * @return array<string, array<string, string>>
     */
    public function limits(array $staff): array
    {
        $roleIds = collect($staff)->pluck('role_ids')->flatten()->unique()->values()->all();

        if ($roleIds === []) {
            return [];
        }

        $rules = DB::connection(TenantContext::CONNECTION)->table('limit_rules')
            ->whereIn('role_id', $roleIds)->get(['role_id', 'key', 'value'])->groupBy('role_id');

        $limits = [];

        foreach ($staff as $userId => $member) {
            $limits[$userId] = [];

            foreach ($member['role_ids'] as $roleId) {
                foreach ($rules->get($roleId, []) as $rule) {
                    $current = $limits[$userId][$rule->key] ?? null;

                    if ($current === null || BigDecimal::of((string) $rule->value)->isGreaterThan($current)) {
                        $limits[$userId][$rule->key] = (string) $rule->value;
                    }
                }
            }

            ksort($limits[$userId]);
        }

        return $limits;
    }

    /**
     * RBAC-05: each user's hidden and read-only fields per resource in
     * FIELD_RESOURCES, over every active role they hold anywhere (as
     * FieldRules::for).
     *
     * @param  list<string>  $userIds
     * @return array<string, array<string, array{hidden: list<string>, readonly: list<string>}>>
     */
    public function fieldRules(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $db = DB::connection(TenantContext::CONNECTION);

        $roles = $db->table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereNull('roles.archived_at')
            ->whereIn('role_assignments.user_id', $userIds)
            ->distinct()
            ->get(['role_assignments.user_id', 'role_assignments.role_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('role_id')->unique()->values()->all());

        $rules = $db->table('field_rules')
            ->whereIn('role_id', $roles->flatten()->unique()->values()->all())
            ->whereIn('resource', self::FIELD_RESOURCES)
            ->get(['role_id', 'resource', 'field', 'mode']);

        $result = [];

        foreach ($userIds as $userId) {
            $roleIds = $roles->get($userId, []);

            foreach (self::FIELD_RESOURCES as $resource) {
                $hidden = [];
                $readonly = [];
                $byField = $rules->where('resource', $resource)->whereIn('role_id', $roleIds)->groupBy('field');

                foreach ($byField as $field => $fieldRules) {
                    if ($roleIds === [] || $fieldRules->pluck('role_id')->unique()->count() < count($roleIds)) {
                        continue;
                    }

                    if ($fieldRules->every(fn (object $rule) => $rule->mode === FieldRule::HIDDEN)) {
                        $hidden[] = $field;
                    } else {
                        $readonly[] = $field;
                    }
                }

                sort($hidden);
                sort($readonly);
                $result[$userId][$resource] = ['hidden' => $hidden, 'readonly' => $readonly];
            }
        }

        return $result;
    }

    /** Assignments at a scope covering the device's location. */
    private function covering(Builder $query, DeviceScope $scope): void
    {
        $query->where('role_assignments.scope_type', Scope::TENANT)
            ->orWhere(fn (Builder $q) => $q->where('role_assignments.scope_type', Scope::COMPANY)->where('role_assignments.scope_id', $scope->companyId()))
            ->orWhere(fn (Builder $q) => $q->where('role_assignments.scope_type', Scope::BRANCH)->where('role_assignments.scope_id', $scope->branch->id))
            ->orWhere(fn (Builder $q) => $q->where('role_assignments.scope_type', Scope::LOCATION)->where('role_assignments.scope_id', $scope->location->id));
    }
}
