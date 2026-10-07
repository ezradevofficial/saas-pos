<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Review focus 4: the permission cache is keyed and flushed per tenant, so a
// warm cache never serves one tenant's roles to another.
class PermissionCacheTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private User $cashierA;

    private User $cashierB;

    private Role $roleA;

    private Role $roleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashierA = $this->createUser();
        $this->cashierB = $this->createUser();

        $this->asTenant($this->cashierA->tenant_id, function () {
            $this->roleA = $this->role('Cashier', ['core.company.view']);
            $this->assign($this->cashierA, $this->roleA, Scope::tenant());
        });

        $this->asTenant($this->cashierB->tenant_id, function () {
            $this->roleB = $this->role('Cashier');
            $this->assign($this->cashierB, $this->roleB, Scope::tenant());
        });
    }

    private function key(string $tenantId): string
    {
        return 'permission.cache.'.$tenantId;
    }

    private function can(User $user, string $permission): bool
    {
        return $this->asTenant($user->tenant_id, fn () => app(ScopeResolver::class)->can($user, $permission));
    }

    public function test_a_warm_cache_in_one_tenant_is_never_served_to_another(): void
    {
        $this->assertTrue($this->can($this->cashierA, 'core.company.view'));
        $this->assertTrue(Cache::has($this->key($this->cashierA->tenant_id)));

        app(TenantContext::class)->set($this->cashierB->tenant_id);
        $this->assertSame($this->key($this->cashierB->tenant_id), app(PermissionRegistrar::class)->cacheKey);
        $this->assertFalse(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
        $this->assertTrue(Cache::has($this->key($this->cashierB->tenant_id)));

        // Back in A, still allowed: B's load did not overwrite A's entry.
        $this->assertTrue($this->can($this->cashierA, 'core.company.view'));
    }

    public function test_the_cache_key_follows_the_tenant_context(): void
    {
        app(TenantContext::class)->set($this->cashierA->tenant_id);
        $this->assertSame($this->key($this->cashierA->tenant_id), app(PermissionRegistrar::class)->cacheKey);

        app(TenantContext::class)->set(null);
        $this->assertSame('permission.cache.none', app(PermissionRegistrar::class)->cacheKey);
    }

    public function test_flushing_one_tenant_leaves_the_other_tenant_cached(): void
    {
        $this->can($this->cashierA, 'core.company.view');
        $this->can($this->cashierB, 'core.company.view');
        $this->assertTrue(Cache::has($this->key($this->cashierA->tenant_id)));
        $this->assertTrue(Cache::has($this->key($this->cashierB->tenant_id)));

        // A role change in A flushes A's cache only.
        $this->asTenant($this->cashierA->tenant_id, fn () => $this->roleA->givePermissionTo('core.branch.view'));

        $this->assertFalse(Cache::has($this->key($this->cashierA->tenant_id)));
        $this->assertTrue(Cache::has($this->key($this->cashierB->tenant_id)));
        $this->assertTrue($this->can($this->cashierA, 'core.branch.view'));
    }

    public function test_a_permission_granted_after_warming_applies_at_once(): void
    {
        $this->assertFalse($this->can($this->cashierB, 'core.company.view'));

        $this->asTenant($this->cashierB->tenant_id, fn () => $this->roleB->givePermissionTo('core.company.view'));

        $this->assertTrue($this->can($this->cashierB, 'core.company.view'));
    }
}
