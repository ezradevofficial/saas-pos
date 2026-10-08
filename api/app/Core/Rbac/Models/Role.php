<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Rbac\Policies\RolePolicy;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A tenant's named set of permissions (RBAC-02). System roles are seeded
 * from templates (RBAC-03) and can be copied but never edited. Roles are
 * archived, not deleted (TEN-06). Changes are audited as `rbac.role.*`
 * (RBAC-12) and flush the current tenant's permission cache (Spatie's
 * RefreshesPermissionCache; the cache key is per tenant).
 *
 * Spatie's users() relation is not used: assignments are scoped and live in
 * role_assignments (RBAC-04).
 *
 * Permission changes go through setPermissions(), which records
 * `rbac.role.permissions_update` with the sorted names before and after
 * (Spatie writes role_has_permissions without model events).
 */
#[UsePolicy(RolePolicy::class)]
class Role extends SpatieRole
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'rbac';

    protected string $auditResource = 'role';

    /** Never inferred from the request's guard (a sanctum request would otherwise look for sanctum rows). */
    protected $guard_name = Permission::GUARD;

    protected $fillable = ['name', 'guard_name', 'description', 'requires_two_factor'];

    protected $attributes = [
        'is_system' => false,
        'is_owner' => false,
        'requires_two_factor' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_owner' => 'boolean',
            'requires_two_factor' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // RBAC-02: whatever the caller, a system role's row never changes.
        static::updating(fn (self $role) => $role->assertEditable(original: true));
    }

    /**
     * Refuse changes to a system role: 403 `system_role` (RBAC-02).
     */
    public function assertEditable(bool $original = false): void
    {
        $isSystem = $original ? (bool) $this->getOriginal('is_system') : $this->is_system;

        if ($isSystem) {
            throw new ApiException(403, 'system_role', __('rbac.errors.system_role'));
        }
    }

    /**
     * Permission names sorted in PHP (byte order), as RoleResource lists
     * them: the database collation may order `core.item_category.*` and
     * `core.item.*` the other way round.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->permissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * Replace the role's permissions (names or models) and audit the change
     * (RBAC-12). No change, no entry. The current tenant's permission cache
     * is flushed by Spatie (after commit, see TenantPermissionRegistrar).
     */
    public function setPermissions(iterable $permissions): static
    {
        return $this->getConnection()->transaction(function () use ($permissions) {
            $before = $this->permissionNames();
            $this->syncPermissions(collect($permissions)->all());
            $this->unsetRelation('permissions');
            $after = $this->permissionNames();

            if ($before !== $after) {
                app(Auditor::class)->record('rbac.role.permissions_update', $this, ['permissions' => $before], ['permissions' => $after]);
            }

            return $this;
        });
    }

    /** RBAC-02: names are unique among the tenant's active roles; an archived role frees its name. */
    protected static function findByParam(array $params = []): ?RoleContract
    {
        $query = static::query()->whereNull('archived_at');

        foreach ($params as $key => $value) {
            $query->where($key, $value);
        }

        return $query->first();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function fieldRules(): HasMany
    {
        return $this->hasMany(FieldRule::class);
    }

    public function limitRules(): HasMany
    {
        return $this->hasMany(LimitRule::class);
    }
}
