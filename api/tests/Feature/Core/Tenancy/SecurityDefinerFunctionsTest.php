<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Platform\PlatformDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-01, ADR 002 (stage 7B closing review): every security-definer
// function in `public` is locked down: a pinned search_path, no execute for
// PUBLIC, and a uuid or setof uuid result (tenant ids only), with the
// documented exceptions; and ADR 002 lists every one of them.
class SecurityDefinerFunctionsTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    /** Functions that return something other than tenant ids, each explained in ADR 002. */
    private const RESULT_EXCEPTIONS = ['app_public_branding', 'app_platform_usage_totals', 'app_prune_api_idempotency_keys',
        // ADR 024 (security review): the marketplace's secrets, never returned as stored.
        'oauth_client_secret_ok', 'app_install_webhook_key', 'marketplace_store_app_secrets', 'marketplace_developer_active'];

    /** @return list<object{name: string, result: string, config: ?string, public_execute: bool}> */
    private function functions(): array
    {
        return DB::connection('pgsql_owner')->select("
            select p.proname as name, pg_get_function_result(p.oid) as result, array_to_string(p.proconfig, ',') as config,
                   exists(select 1 from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a where a.grantee = 0 and a.privilege_type = 'EXECUTE') as public_execute
            from pg_proc p join pg_namespace n on n.oid = p.pronamespace
            where n.nspname = 'public' and p.prosecdef
            order by p.proname
        ");
    }

    public function test_every_security_definer_function_is_locked_down(): void
    {
        $functions = $this->functions();
        $this->assertNotEmpty($functions);

        foreach ($functions as $function) {
            $this->assertStringContainsString('search_path=pg_catalog, public', (string) $function->config, "{$function->name} pins its search_path");
            $this->assertFalse((bool) $function->public_execute, "{$function->name} is not executable by PUBLIC");

            if (! in_array($function->name, self::RESULT_EXCEPTIONS, true)) {
                $this->assertContains($function->result, ['uuid', 'SETOF uuid'], "{$function->name} returns tenant ids only");
            }
        }
    }

    /** ADM-06: the usage aggregate answers totals per unit only: no tenant id, no row. */
    public function test_the_platform_usage_aggregate_returns_totals_only(): void
    {
        $function = collect($this->functions())->firstWhere('name', 'app_platform_usage_totals');
        $this->assertNotNull($function);
        $this->assertSame('TABLE(unit text, tenants bigint, total bigint)', $function->result);
    }

    /**
     * Phase 8 review L5: the two platform-wide functions answer the platform role, or a
     * runtime session with no tenant in context (the staff console), and no tenant session.
     */
    public function test_the_platform_wide_functions_answer_no_tenant_session(): void
    {
        $this->setUpOrganisation();
        $this->inTenant(fn () => DB::table('usage_meters')->insert([
            'id' => (string) Str::uuid7(), 'day' => '2026-11-01', 'unit' => 'user', 'count' => 3, 'metered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $owned = fn () => array_column(DB::select('select t.id from public.app_tenants_owned_by_email(?) as t(id)', [$this->owner->email]), 'id');
        $usage = fn () => DB::select("select unit, tenants, total from public.app_platform_usage_totals('2026-11-01'::date)");

        // The staff console: the runtime role with no tenant in context.
        $this->assertSame([$this->owner->tenant_id], $owned());
        $this->assertSame([['user', 1, 3]], array_map(fn ($r) => [$r->unit, (int) $r->tenants, (int) $r->total], $usage()));

        // Any tenant session, its own included, gets nothing.
        $this->assertSame([], $this->inTenant($owned));
        $this->assertSame([], $this->inTenant($usage));
        $other = $this->otherTenant();
        $this->assertSame([], $this->asTenant($other['user']->tenant_id, $owned));
        $this->assertSame([], $this->asTenant($other['user']->tenant_id, $usage));

        // The platform role may call both (its own session: nothing of this test's transaction is visible to it).
        $this->assertIsArray(PlatformDatabase::connection()->select('select t.id from public.app_tenants_owned_by_email(?) as t(id)', ['nobody@example.com']));
        $this->assertIsArray(PlatformDatabase::connection()->select("select * from public.app_platform_usage_totals('2026-11-01'::date)"));
    }

    /** ADR 024: the marketplace's secret functions answer a yes or no, one install's derived key, or a count. */
    public function test_the_marketplace_secret_functions_return_no_stored_secret(): void
    {
        $results = collect($this->functions())->pluck('result', 'name');

        $this->assertSame('boolean', $results['oauth_client_secret_ok']);
        $this->assertSame('text', $results['app_install_webhook_key']);
        $this->assertSame('integer', $results['marketplace_store_app_secrets']);
        $this->assertSame('boolean', $results['marketplace_developer_active']);
        $this->assertArrayNotHasKey('marketplace_hmac_sha256', $results->all(), 'the HMAC helper runs as its caller');
    }

    public function test_adr_002_lists_every_security_definer_function(): void
    {
        $adr = (string) file_get_contents(base_path('../docs/adr/002-tenancy-rls.md'));

        foreach ($this->functions() as $function) {
            $this->assertStringContainsString("| `{$function->name}(", $adr, "ADR 002 lists {$function->name}");
        }
    }
}
