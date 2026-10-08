<?php

namespace Tests\Feature\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ADR 002: the scheduler finds the tenants with work due through
 * owner-owned security-definer functions that return only tenant ids. Each
 * one pins its search_path, is not executable by PUBLIC and is executable
 * by the runtime role.
 */
class DueTenantsTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_the_scheduler_functions_are_locked_down_security_definers_returning_ids(): void
    {
        $functions = [
            'app_tenants_with_due_approval_timers',
            'app_tenants_with_due_automation',
            'app_tenants_with_stuck_automation',
            'app_tenants_with_pending_digests',
            'app_active_tenant_ids',
            'app_tenants_with_due_stage_timers',
            'app_tenants_with_unsettled_credit_changes',
        ];
        $runtime = DB::selectOne('select current_user as name')->name;
        $owner = config('database.connections.pgsql_owner.username');

        foreach ($functions as $name) {
            $fn = DB::selectOne(<<<'SQL'
                select p.oid, p.prosecdef, p.proretset, p.proconfig::text as config,
                       pg_get_userbyid(p.proowner) as owner, format_type(p.prorettype, null) as returns,
                       has_function_privilege('public', p.oid, 'execute') as public_execute
                from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                where n.nspname = 'public' and p.proname = ?
                SQL, [$name]);

            $this->assertNotNull($fn, "{$name} exists");
            $this->assertTrue($fn->prosecdef, "{$name} is security definer");
            $this->assertTrue($fn->proretset, "{$name} returns a set");
            $this->assertSame('uuid', $fn->returns, "{$name} returns only tenant ids");
            $this->assertSame($owner, $fn->owner, "{$name} is owned by the schema owner");
            $this->assertStringContainsString('search_path=pg_catalog, public', $fn->config, "{$name} pins search_path");
            $this->assertFalse($fn->public_execute, "{$name} is revoked from PUBLIC");
            $this->assertTrue(DB::selectOne('select has_function_privilege(?, ?::oid, ?) as ok', [$runtime, $fn->oid, 'execute'])->ok, "{$name} is granted to the runtime role");
        }
    }
}
