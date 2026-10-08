<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-01, TEN-01: the global catalogue is written only by the schema
// owner. Deleting a permission cascades to role_has_permissions of every
// tenant, so the runtime role must not be able to.
class PermissionCatalogueWritesTest extends TestCase
{
    use RefreshTenantDatabase;

    private function assertDenied(callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('The runtime role wrote to a global table');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
    }

    public function test_the_runtime_role_cannot_write_the_permission_catalogue(): void
    {
        $count = Permission::count();

        $this->assertDenied(fn () => DB::update("update permissions set name = 'core.company.tampered' where name = 'core.company.view'"));
        $this->assertDenied(fn () => DB::delete('delete from permissions'));
        $this->assertDenied(fn () => DB::insert(
            "insert into permissions (id, name, guard_name, module, resource, action) values (gen_random_uuid(), 'core.evil.view', 'web', 'core', 'evil', 'view')",
        ));
        $this->assertDenied(fn () => DB::statement('truncate permissions cascade'));
        $this->assertDenied(fn () => Permission::where('name', 'core.company.view')->delete());

        $this->assertSame($count, Permission::count());
        $this->assertTrue(Permission::where('name', 'core.company.view')->exists());

        foreach (['permissions', 'migrations'] as $table) {
            $this->assertTrue((bool) DB::selectOne("select has_table_privilege(current_user, '{$table}', 'SELECT') as p")->p);

            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse(
                    (bool) DB::selectOne("select has_table_privilege(current_user, '{$table}', '{$privilege}') as p")->p,
                    "{$privilege} on {$table}",
                );
            }
        }
    }

    public function test_the_runtime_role_cannot_rewrite_the_migrations_table(): void
    {
        $this->assertDenied(fn () => DB::delete('delete from migrations'));
        $this->assertDenied(fn () => DB::update("update migrations set migration = 'x'"));
    }

    public function test_permissions_sync_still_writes_the_catalogue_as_the_owner(): void
    {
        app(PermissionRegistry::class)->register('core', ['sync_probe' => ['view']]);

        $this->syncPermissionCatalogue();

        $permission = Permission::where('name', 'core.sync_probe.view')->sole();
        $this->assertSame(['core', 'sync_probe', 'view'], [$permission->module, $permission->resource, $permission->action]);
    }
}
