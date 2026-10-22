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

    /**
     * NFR-01, NFR-06 (Phase 8 walkthrough): Laravel's /up HTML page loaded fonts from a
     * third-party CDN. /up stays (it carries the sync lag headers, NFR-04, and is never in
     * maintenance) but always answers JSON, with no page and no external resource.
     */
    public function test_up_answers_json_without_a_page_or_external_resources(): void
    {
        $response = $this->get('/up', ['Accept' => 'text/html'])->assertOk();

        $response->assertExactJson(['status' => 'up']);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('fonts.bunny.net', (string) $response->getContent());
        $response->assertHeader('X-Sync-Lag-Seconds');
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
