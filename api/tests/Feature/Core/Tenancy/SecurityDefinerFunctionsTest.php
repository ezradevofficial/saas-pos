<?php

namespace Tests\Feature\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-01, ADR 002 (stage 7B closing review): every security-definer
// function in `public` is locked down: a pinned search_path, no execute for
// PUBLIC, and a uuid or setof uuid result (tenant ids only), with the
// documented exceptions; and ADR 002 lists every one of them.
class SecurityDefinerFunctionsTest extends TestCase
{
    use RefreshTenantDatabase;

    /** Functions that return something other than tenant ids, each explained in ADR 002. */
    private const RESULT_EXCEPTIONS = ['app_public_branding', 'app_platform_usage_totals'];

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

    public function test_adr_002_lists_every_security_definer_function(): void
    {
        $adr = (string) file_get_contents(base_path('../docs/adr/002-tenancy-rls.md'));

        foreach ($this->functions() as $function) {
            $this->assertStringContainsString("| `{$function->name}(", $adr, "ADR 002 lists {$function->name}");
        }
    }
}
