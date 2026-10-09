<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// BR-04, BR-05 review hardening.
//
// - A host is unique among verified domains only: several tenants may hold
//   pending claims, and the first to prove the TXT record wins (the others
//   fail with `claimed_elsewhere` when they next check). Before, a pending
//   claim blocked the real owner (domain squatting).
// - missed_checks / failed_at: verified domains are checked again daily;
//   three missed checks in a row drop the domain to failed. Failed claims
//   are archived after 7 days.
// - app_tenants_with_pending_domains() is limited to active tenants;
//   app_tenants_with_domain_checks_due(at) finds every tenant with a domain
//   to check, re-check or archive (active tenants only).
// - app_public_branding reads only the tenant-scope theme (scope_id null)
//   and its live, not archived version.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('drop index if exists tenant_domains_host_unique');
        DB::statement("create unique index tenant_domains_verified_host_unique on tenant_domains (host) where status = 'verified' and archived_at is null");
        DB::statement('create index tenant_domains_host_index on tenant_domains (host)');

        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->smallInteger('missed_checks')->default(0);
            $table->timestampTz('failed_at')->nullable();
        });

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::unprepared(<<<'SQL'
            create or replace function public.app_public_branding(p_slug text, p_host text)
            returns table (
                tenant_name text, preset text, colors jsonb, sidebar text, corners text, font text,
                welcome text, logo_light text, logo_dark text, favicon text, background text, hide_platform boolean
            )
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                with t as (
                    select t.id, t.name, t.settings from public.tenants t
                    where t.status = 'active'
                      and ((p_slug is not null and t.slug = lower(p_slug))
                        or (p_host is not null and exists (
                            select 1 from public.tenant_domains d
                            where d.tenant_id = t.id and d.host = lower(p_host)
                              and d.status = 'verified' and d.archived_at is null)))
                    order by t.id
                    limit 1
                ), theme as (
                    select v.payload as p from t
                    join public.config_documents cd on cd.tenant_id = t.id and cd.kind = 'theme'
                        and cd.key = 'default' and cd.scope_type = 'tenant' and cd.scope_id is null
                    join public.config_versions v on v.document_id = cd.id and v.tenant_id = t.id
                        and v.status = 'published' and v.archived_at is null
                )
                select t.name::text,
                       theme.p->>'preset', theme.p->'colors', theme.p->>'sidebar', theme.p->>'corners', theme.p->>'font',
                       theme.p->'login'->>'welcome',
                       (select a.path from public.brand_assets a where a.tenant_id = t.id and a.id::text = theme.p->>'logo_light'),
                       (select a.path from public.brand_assets a where a.tenant_id = t.id and a.id::text = theme.p->>'logo_dark'),
                       (select a.path from public.brand_assets a where a.tenant_id = t.id and a.id::text = theme.p->>'favicon'),
                       (select a.path from public.brand_assets a where a.tenant_id = t.id and a.id::text = theme.p->'login'->>'background'),
                       coalesce((t.settings->'branding'->>'hide_platform')::boolean, false)
                from t left join theme on true
            $fn$;

            SQL);

        foreach ([
            'app_tenants_with_pending_domains()' => <<<'SQL'
                select distinct d.tenant_id from public.tenant_domains d
                join public.tenants t on t.id = d.tenant_id and t.status = 'active'
                where d.status = 'pending' and d.archived_at is null
                order by 1
                SQL,
            'app_tenants_with_domain_checks_due(p_at timestamptz)' => <<<'SQL'
                select distinct d.tenant_id from public.tenant_domains d
                join public.tenants t on t.id = d.tenant_id and t.status = 'active'
                where d.archived_at is null
                  and (d.status = 'pending'
                    or (d.status = 'verified' and (d.checked_at is null or d.checked_at <= p_at - interval '20 hours'))
                    or (d.status = 'failed' and d.failed_at <= p_at - interval '7 days'))
                order by 1
                SQL,
        ] as $signature => $body) {
            $name = strstr($signature, '(', true);
            $types = str_contains($signature, 'p_at') ? 'timestamptz' : '';

            DB::unprepared(<<<SQL
                create or replace function public.{$signature} returns setof uuid
                language sql stable security definer
                set search_path = pg_catalog, public
                as \$fn\$
                {$body}
                \$fn\$;

                revoke all on function public.{$name}({$types}) from public;
                SQL);

            DB::statement("grant execute on function public.{$name}({$types}) to {$runtimeRole}");
        }
    }

    public function down(): void
    {
        DB::unprepared('drop function if exists public.app_tenants_with_domain_checks_due(timestamptz)');
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn(['missed_checks', 'failed_at']);
        });
        DB::statement('drop index if exists tenant_domains_verified_host_unique');
        DB::statement('drop index if exists tenant_domains_host_index');
        DB::statement('create unique index tenant_domains_host_unique on tenant_domains (host) where archived_at is null');
    }
};
