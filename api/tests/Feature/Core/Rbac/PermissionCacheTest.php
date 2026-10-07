<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\TenantPermissionRegistrar;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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

    public function test_the_transaction_safe_registrar_is_bound(): void
    {
        $this->assertInstanceOf(TenantPermissionRegistrar::class, app(PermissionRegistrar::class));
    }

    public function test_a_cache_rebuilt_from_pre_commit_data_is_flushed_on_commit(): void
    {
        app(TenantContext::class)->set($this->cashierB->tenant_id);
        $key = $this->key($this->cashierB->tenant_id);

        $this->assertFalse(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
        $preCommit = Cache::get($key);
        $this->assertNotNull($preCommit);

        DB::transaction(function () use ($key, $preCommit) {
            $this->roleB->givePermissionTo('core.company.view');

            // A concurrent request rebuilds the cache from committed (old) data
            // while this transaction is open; this process holds it in memory too.
            Cache::put($key, $preCommit);
            app(PermissionRegistrar::class)->clearPermissionsCollection();
            app(PermissionRegistrar::class)->getPermissions();
        });

        $this->assertFalse(Cache::has($key));
        $this->assertTrue(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
    }

    public function test_a_rolled_back_grant_is_never_cached(): void
    {
        app(TenantContext::class)->set($this->cashierB->tenant_id);
        $key = $this->key($this->cashierB->tenant_id);

        try {
            DB::transaction(function () use ($key) {
                $this->roleB->givePermissionTo('core.company.view');

                // Inside the transaction the grant is visible, but not stored.
                $this->assertTrue(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
                $this->assertFalse(Cache::has($key));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
        $this->assertTrue(Cache::has($key));
        $this->assertFalse(app(ScopeResolver::class)->can($this->cashierB, 'core.company.view'));
    }

    public function test_an_unsynced_permission_rebuilds_the_cache_only_once(): void
    {
        app(PermissionRegistry::class)->register('core', ['ghost' => ['view']]);
        app(TenantContext::class)->set($this->cashierA->tenant_id);
        app(ScopeResolver::class)->can($this->cashierA, 'core.company.view');

        DB::enableQueryLog();
        foreach (range(1, 3) as $i) {
            $this->assertFalse(app(ScopeResolver::class)->can($this->cashierA, 'core.ghost.view'));
        }
        $loads = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "permissions"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $loads);
    }
}
