<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Super-admin review fixes (docs/modules/platform-admin.md):
// - H3: app_tenants_for_pack_updates: every tenant that is not closed
//   (suspended ones included) gets a published tax release; tenant ids only.
// - L3: app_tenants_owned_by_email: the console's owner-email search finds
//   every tenant where the email is an active Owner (tenant ids only).
// - L1: a support session's entry code is bound to the console session that
//   asked for it (a hash of its user agent).
// - L8: the runtime role reads only published pack releases (row-level
//   security on the global table; the platform role sees them all). The usage
//   aggregate stays with the runtime role: the staff reports read the
//   operator's data through it too (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_for_pack_updates() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.id from public.tenants t where t.status <> 'closed'
            $$;
            revoke all on function public.app_tenants_for_pack_updates() from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_for_pack_updates() to {$runtime}");

        // L3: the tenants where an email belongs to an active Owner (tenant ids only).
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
            revoke all on function public.app_tenants_owned_by_email(text) from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_owned_by_email(text) to {$runtime}");

        Schema::table('support_access_grants', function (Blueprint $table) {
            $table->char('entry_code_agent_hash', 64)->nullable();
        });

        DB::statement('alter table country_pack_releases enable row level security');
        DB::statement("create policy published_only on country_pack_releases for select to {$runtime} using (status = 'published')");
        DB::statement("create policy platform_all on country_pack_releases for all to {$platform} using (true) with check (true)");
    }

    public function down(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');
        DB::statement('drop policy if exists platform_all on country_pack_releases');
        DB::statement('drop policy if exists published_only on country_pack_releases');
        DB::statement('alter table country_pack_releases disable row level security');
        Schema::table('support_access_grants', fn (Blueprint $table) => $table->dropColumn('entry_code_agent_hash'));
        DB::statement('drop function if exists public.app_tenants_owned_by_email(text)');
        DB::statement('drop function if exists public.app_tenants_for_pack_updates()');
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
