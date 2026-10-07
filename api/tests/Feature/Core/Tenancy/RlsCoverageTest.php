<?php

namespace Tests\Feature\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-01: every tenant table is isolated by forced row-level security.
class RlsCoverageTest extends TestCase
{
    use RefreshTenantDatabase;

    /**
     * Tables with a tenant_id that are deliberately global: they are read
     * before the tenant is known (bearer token lookup, sign-up and sign-in
     * codes) and are only touched by dedicated services. See
     * docs/adr/002-tenancy-rls.md.
     */
    public const GLOBAL_TABLES = ['personal_access_tokens', 'verification_challenges'];

    public function test_every_tenant_table_forces_row_level_security(): void
    {
        $tables = collect(DB::select("
            select c.relname, c.relrowsecurity, c.relforcerowsecurity,
                   exists(select 1 from pg_policies p where p.schemaname = n.nspname and p.tablename = c.relname and p.policyname = 'tenant_isolation') as has_policy
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
              and exists(select 1 from information_schema.columns col
                         where col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'tenant_id')
        "))->reject(fn ($t) => in_array($t->relname, self::GLOBAL_TABLES, true));
        $this->assertNotEmpty($tables);
        foreach ($tables as $t) {
            $this->assertTrue($t->relrowsecurity && $t->relforcerowsecurity && $t->has_policy, "{$t->relname} lacks forced RLS");
        }
    }

    public function test_only_the_documented_tables_are_global(): void
    {
        $this->assertSame(['personal_access_tokens', 'verification_challenges'], self::GLOBAL_TABLES);

        $tables = collect(DB::select("
            select table_name from information_schema.columns
            where table_schema = 'public' and column_name = 'tenant_id' and table_name = any(?)
            order by table_name
        ", ['{'.implode(',', self::GLOBAL_TABLES).'}']))->pluck('table_name')->all();

        $this->assertSame(self::GLOBAL_TABLES, $tables, 'every allow-listed table must exist with a tenant_id');
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
