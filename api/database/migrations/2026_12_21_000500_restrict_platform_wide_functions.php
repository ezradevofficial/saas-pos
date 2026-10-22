<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase 8 review L5 (ADR 002): two platform-wide security-definer functions
// were granted to the runtime role with no session check, so any tenant
// session could list the tenants an email owns, or read usage totals across
// tenants. Each now answers only:
// - the platform role (`session_user`), to which it is also granted; or
// - a runtime session with no tenant in context, which is how the staff
//   console's tenant search (PlatformTenants) and reports (PlatformReports)
//   call them. Every tenant request, job and command runs with its tenant
//   set (ADR 002), so none of them gets an answer.
return new class extends Migration
{
    public function up(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platformName = config('database.connections.pgsql_platform.username') ?: 'app_platform';
        $platform = $this->quoted($platformName);

        DB::unprepared(str_replace('__PLATFORM__', str_replace("'", "''", $platformName), <<<'SQL'
            create or replace function public.app_tenants_owned_by_email(p_email text) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct a.tenant_id from public.users u
                join public.role_assignments a on a.user_id = u.id and a.tenant_id = u.tenant_id and a.scope_type = 'tenant'
                join public.roles r on r.id = a.role_id and r.tenant_id = u.tenant_id and r.is_owner and r.archived_at is null
                where u.email = p_email::public.citext and u.status = 'active' and u.support_grant_id is null
                  and (session_user = '__PLATFORM__' or nullif(pg_catalog.current_setting('app.tenant_id', true), '') is null)
            $$;
            revoke all on function public.app_tenants_owned_by_email(text) from public;

            create or replace function public.app_platform_usage_totals(p_day date)
            returns table (unit text, tenants bigint, total bigint)
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select m.unit::text, count(distinct m.tenant_id), coalesce(sum(m.count), 0)::bigint
                from public.usage_meters m
                where m.day = (select max(x.day) from public.usage_meters x where x.day <= p_day)
                  and (session_user = '__PLATFORM__' or nullif(pg_catalog.current_setting('app.tenant_id', true), '') is null)
                group by m.unit
                order by m.unit
            $$;
            revoke all on function public.app_platform_usage_totals(date) from public;
            SQL));

        foreach (['public.app_tenants_owned_by_email(text)', 'public.app_platform_usage_totals(date)'] as $function) {
            DB::statement("grant execute on function {$function} to {$runtime}, {$platform}");
        }
    }

    public function down(): void
    {
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');

        foreach (['public.app_tenants_owned_by_email(text)', 'public.app_platform_usage_totals(date)'] as $function) {
            DB::statement("revoke execute on function {$function} from {$platform}");
        }

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_owned_by_email(p_email text) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct a.tenant_id from public.users u
                join public.role_assignments a on a.user_id = u.id and a.tenant_id = u.tenant_id and a.scope_type = 'tenant'
                join public.roles r on r.id = a.role_id and r.tenant_id = u.tenant_id and r.is_owner and r.archived_at is null
                where u.email = p_email::public.citext and u.status = 'active' and u.support_grant_id is null
            $$;

            create or replace function public.app_platform_usage_totals(p_day date)
            returns table (unit text, tenants bigint, total bigint)
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select m.unit::text, count(distinct m.tenant_id), coalesce(sum(m.count), 0)::bigint
                from public.usage_meters m
                where m.day = (select max(x.day) from public.usage_meters x where x.day <= p_day)
                group by m.unit
                order by m.unit
            $$;
            SQL);
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
