<?php

namespace Tests\Feature\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\GlobalTables;
use Tests\TestCase;

// TEN-01: every tenant table is isolated by forced row-level security.
class RlsCoverageTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_every_tenant_table_forces_row_level_security(): void
    {
        $tables = collect(DB::select("
            select c.relname, c.relrowsecurity, c.relforcerowsecurity,
                   exists(select 1 from pg_policies p where p.schemaname = n.nspname and p.tablename = c.relname and p.policyname = 'tenant_isolation') as has_policy
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
              and exists(select 1 from information_schema.columns col
                         where col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'tenant_id')
        "))->reject(fn ($t) => in_array($t->relname, GlobalTables::TABLES, true));
        $this->assertNotEmpty($tables);
        foreach ($tables as $t) {
            $this->assertTrue($t->relrowsecurity && $t->relforcerowsecurity && $t->has_policy, "{$t->relname} lacks forced RLS");
        }
    }

    public function test_only_the_documented_tables_are_global(): void
    {
        $tables = collect(DB::select("
            select table_name from information_schema.columns
            where table_schema = 'public' and column_name = 'tenant_id' and table_name = any(?)
            order by table_name
        ", ['{'.implode(',', GlobalTables::TABLES).'}']))->pluck('table_name')->all();

        $this->assertSame(GlobalTables::TABLES, $tables, 'every allow-listed table must exist with a tenant_id');
    }

    /** ADR 013: platform tables are global by design and never hold a tenant_id. */
    public function test_platform_tables_are_global_and_hold_no_tenant_id(): void
    {
        $tables = array_keys(GlobalTables::PLATFORM);
        // pg_catalog, not information_schema: the runtime role cannot see staff tables there.
        $found = collect(DB::select("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' and c.relname = any(?)", ['{'.implode(',', $tables).'}']))->pluck('relname')->sort()->values()->all();
        $this->assertSame(collect($tables)->sort()->values()->all(), $found, 'every documented platform table must exist');

        $withTenant = collect(DB::select("select c.relname from pg_attribute a join pg_class c on c.oid = a.attrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and a.attname = 'tenant_id' and not a.attisdropped and c.relname = any(?)", ['{'.implode(',', $tables).'}']))->pluck('relname')->all();
        $this->assertSame([], $withTenant, 'a platform table must not hold tenant rows');
    }

    /** ADR 013: the runtime role has no access to staff tables and only reads the catalogue; the platform role writes them. */
    public function test_platform_tables_belong_to_the_platform_role(): void
    {
        $runtime = config('database.connections.pgsql.username');
        $platform = config('database.connections.pgsql_platform.username');
        $can = fn (string $role, string $table, string $privilege) => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as can', [$role, "public.{$table}", $privilege])->can;

        foreach (array_keys(GlobalTables::PLATFORM) as $table) {
            $staff = str_starts_with($table, 'platform_');
            $this->assertSame(! $staff, $can($runtime, $table, 'SELECT'), "{$runtime} SELECT on {$table}");
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse($can($runtime, $table, $privilege), "{$runtime} {$privilege} on {$table}");
            }
            $this->assertTrue($can($platform, $table, 'SELECT'), "{$platform} SELECT on {$table}");
            $this->assertFalse($can($platform, $table, 'TRUNCATE'), "{$platform} TRUNCATE on {$table}");
        }

        // The platform role reaches no tenant table.
        $tenantTables = collect(DB::select("select table_name from information_schema.columns where table_schema = 'public' and column_name = 'tenant_id'"))->pluck('table_name');
        foreach ($tenantTables as $table) {
            $this->assertFalse($can($platform, $table, 'SELECT'), "{$platform} SELECT on {$table}");
        }
    }

    /** ADR 024: marketplace tables are global, under forced RLS keyed on the developer tenant; nobody deletes. */
    public function test_marketplace_tables_are_global_under_developer_policies(): void
    {
        $runtime = config('database.connections.pgsql.username');
        $platform = config('database.connections.pgsql_platform.username');
        $can = fn (string $role, string $table, string $privilege) => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as can', [$role, "public.{$table}", $privilege])->can;

        foreach (array_keys(GlobalTables::MARKETPLACE) as $table) {
            $row = DB::selectOne("select c.relrowsecurity, c.relforcerowsecurity, (select count(*) from pg_policies p where p.tablename = c.relname) as policies,
                exists(select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped) as has_tenant
                from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relname = ?", [$table]);
            $this->assertNotNull($row, "{$table} is missing");
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$table} lacks forced RLS");
            $this->assertGreaterThanOrEqual(4, (int) $row->policies, "{$table} lacks its policies");
            $this->assertFalse($row->has_tenant, "{$table} must not hold a tenant_id");
            $this->assertTrue($can($runtime, $table, 'SELECT') && $can($runtime, $table, 'INSERT'), "{$runtime} on {$table}");
            $this->assertTrue($can($platform, $table, 'SELECT'), "{$platform} SELECT on {$table}");
            foreach ([$runtime, $platform] as $role) {
                foreach (['DELETE', 'TRUNCATE'] as $privilege) {
                    $this->assertFalse($can($role, $table, $privilege), "{$role} {$privilege} on {$table}");
                }
            }
            $this->assertFalse($can($platform, $table, 'INSERT'), "{$platform} INSERT on {$table}");
        }

        // Review fields are the platform's: the runtime role cannot list an app or approve a version.
        $column = fn (string $role, string $table, string $col) => (bool) DB::selectOne('select has_column_privilege(?, ?, ?, ?) as can', [$role, "public.{$table}", $col, 'UPDATE'])->can;
        $this->assertFalse($column($runtime, 'marketplace_apps', 'listed_version_id'));
        $this->assertFalse($column($runtime, 'marketplace_app_versions', 'reviewed_by'));
        $this->assertFalse($column($runtime, 'marketplace_developers', 'status'));
        $this->assertTrue($column($platform, 'marketplace_apps', 'listed_version_id'));
    }

    public function test_tenants_table_forces_row_level_security_keyed_on_id(): void
    {
        $table = DB::selectOne("
            select c.relrowsecurity, c.relforcerowsecurity
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relname = 'tenants'
        ");
        $this->assertNotNull($table, 'tenants table is missing');
        $this->assertTrue($table->relrowsecurity && $table->relforcerowsecurity, 'tenants lacks forced RLS');

        $policy = DB::selectOne("select qual, with_check from pg_policies where tablename = 'tenants' and policyname = 'tenant_isolation'");
        $this->assertNotNull($policy, 'tenants lacks the tenant_isolation policy');
        $this->assertStringContainsString('(id =', $policy->qual);
        $this->assertStringContainsString('(id =', $policy->with_check);
    }

    public function test_tenant_id_defaults_to_the_session_tenant(): void
    {
        $default = DB::selectOne("
            select column_default, is_nullable from information_schema.columns
            where table_schema = 'public' and table_name = 'companies' and column_name = 'tenant_id'
        ");
        $this->assertNotNull($default);
        $this->assertSame('NO', $default->is_nullable);
        $this->assertStringContainsString("current_setting('app.tenant_id'", $default->column_default);
    }
}
