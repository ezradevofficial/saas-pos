<?php

namespace App\Core\Rbac;

use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System role templates (RBAC-03): seeded per tenant from role-templates.php,
 * refreshed when the catalogue a tenant uses grows (module activation), and
 * copied into editable roles (RBAC-02).
 */
class RoleTemplates
{
    /** @var list<array{key: string, permissions: list<string>, except?: list<string>, limits?: array<string, string>, is_owner: bool, requires_two_factor: bool}>|null */
    private ?array $templates = null;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<array{key: string, permissions: list<string>, except?: list<string>, limits?: array<string, string>, is_owner: bool, requires_two_factor: bool}> */
    public function all(): array
    {
        return $this->templates ??= require __DIR__.'/role-templates.php';
    }

    /**
     * Seed every template into $tenant as a system role, named in the
     * tenant's default locale.
     *
     * @return Collection<int, Role> keyed by template key
     */
    public function provision(Tenant $tenant): Collection
    {
        return $this->tenants->run($tenant->id, fn () => $this->transaction(function () use ($tenant) {
            $roles = new Collection;

            foreach ($this->all() as $template) {
                $roles->put($template['key'], $this->seed($tenant, $template));
            }

            return $roles;
        }));
    }

    /**
     * ONB-02: the current tenant's system roles for $keys, seeding any
     * template it does not have yet (a tenant from before the template
     * existed). Unknown keys are ignored.
     *
     * @param  list<string>  $keys
     * @return Collection<int, Role> keyed by template key
     */
    public function ensure(Tenant $tenant, array $keys): Collection
    {
        return $this->tenants->run($tenant->id, fn () => $this->transaction(function () use ($tenant, $keys) {
            $existing = Role::where('is_system', true)->whereIn('template_key', $keys)->get()->keyBy('template_key');
            $roles = new Collection;

            foreach ($this->all() as $template) {
                if (in_array($template['key'], $keys, true)) {
                    $roles->put($template['key'], $existing->get($template['key']) ?? $this->seed($tenant, $template));
                }
            }

            return $roles;
        }));
    }

    /** @param array{key: string, permissions: list<string>, is_owner: bool, requires_two_factor: bool} $template */
    private function seed(Tenant $tenant, array $template): Role
    {
        $role = new Role([
            'name' => __("rbac.templates.{$template['key']}", [], $tenant->default_locale),
            'guard_name' => Permission::GUARD,
        ]);
        $role->forceFill([
            'is_system' => true,
            'template_key' => $template['key'],
            'is_owner' => $template['is_owner'],
            'requires_two_factor' => $template['requires_two_factor'],
        ])->save();

        $role->syncPermissions($this->expand($template['permissions'], $template['except'] ?? []));

        // RBAC-06: a template's default limits (e.g. the sales rep's 0 % discount, docs/modules/crm.md), seeded once.
        foreach ($template['limits'] ?? [] as $key => $value) {
            $role->limitRules()->create(['key' => $key, 'value' => $value]);
        }

        return $role;
    }

    /**
     * Re-apply the templates to the current tenant's system roles, so they
     * pick up permissions registered since they were seeded. Changes are
     * audited (RBAC-12).
     */
    public function refresh(): void
    {
        $this->tenants->require();
        $templates = collect($this->all())->keyBy('key');

        $this->transaction(function () use ($templates) {
            Role::where('is_system', true)->whereNotNull('template_key')->get()
                ->each(function (Role $role) use ($templates) {
                    if ($template = $templates->get($role->template_key)) {
                        $role->setPermissions($this->expand($template['permissions'], $template['except'] ?? []));
                    }
                });
        });
    }

    /**
     * An editable copy of $role (any role, typically a system one) with the
     * same permissions, field rules and limit rules (RBAC-02). Never an
     * owner role. The permissions are audited (RBAC-12).
     */
    public function copy(Role $role, string $name): Role
    {
        return $this->transaction(function () use ($role, $name) {
            $copy = Role::create([
                'name' => $name,
                'guard_name' => $role->guard_name,
                'description' => $role->description,
                'requires_two_factor' => $role->requires_two_factor,
            ]);

            $copy->setPermissions($role->permissions()->get());

            foreach ($role->fieldRules as $rule) {
                $copy->fieldRules()->create($rule->only(['resource', 'field', 'mode']));
            }

            foreach ($role->limitRules as $rule) {
                $copy->limitRules()->create($rule->only(['key', 'value']));
            }

            return $copy;
        });
    }

    /**
     * Catalogue permissions matching any of $patterns (`*` is a wildcard).
     *
     * @param  list<string>  $patterns
     * @param  list<string>  $except  exact names left out
     * @return Collection<int, Permission>
     */
    public function expand(array $patterns, array $except = []): Collection
    {
        return Permission::where('guard_name', Permission::GUARD)->orderBy('name')->get()
            ->filter(fn (Permission $permission) => Str::is($patterns, $permission->name) && ! in_array($permission->name, $except, true))
            ->values();
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
