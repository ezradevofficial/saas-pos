<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_tests_run_against_postgresql(): void
    {
        // Tenant isolation relies on PostgreSQL row-level security (TEN-01),
        // so tests must never fall back to SQLite.
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('app_test', DB::connection()->getDatabaseName());
    }

    public function test_runtime_role_cannot_bypass_row_level_security(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_runtime_role_cannot_create_objects_in_the_public_schema(): void
    {
        $this->assertFalse(DB::selectOne("select has_schema_privilege(current_user, 'public', 'CREATE') as can")->can);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied for schema public');

        DB::transaction(fn () => DB::statement('create table planted (id int)'));
    }
}
