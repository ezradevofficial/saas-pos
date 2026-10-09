<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// BR-02, BR-04, BR-05, TEN-01: branding.
//
// - tenants.slug: the tenant's subdomain ({slug}.APP_BASE_DOMAIN), unique
//   across tenants, lower-case letters, digits and hyphens;
// - brand_assets: logos, favicons and sign-in backgrounds on the media
//   disk, referenced by id from the theme configuration;
// - tenant_domains: custom domains, verified by a DNS TXT record. A host
//   belongs to one tenant at a time (unique among those not archived).
//
// Two owner-owned security-definer functions answer before any tenant is
// known (ADR 002): the public branding of a host, and the tenants with
// domains waiting for verification. A third tells the TLS ask endpoint
// whether a host is a verified domain.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug', 63)->nullable()->unique();
        });

        DB::statement("alter table tenants add constraint tenants_slug_check check (slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$')");

        Schema::create('brand_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // logo | favicon | background
            $table->string('kind', 20);
            $table->string('disk', 40);
            $table->string('path', 255)->unique();
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table brand_assets add constraint brand_assets_kind_check check (kind in ('logo', 'favicon', 'background'))");
        Rls::enable('brand_assets');

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('host', 253);
            // pending | verified | failed
            $table->string('status', 20)->default('pending');
            $table->string('verification_token', 64);
            // When the current wait for the TXT record started (added, or "Check now" after a failure).
            $table->timestampTz('pending_since')->useCurrent();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('failure', 40)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table tenant_domains add constraint tenant_domains_status_check check (status in ('pending', 'verified', 'failed'))");
        DB::statement("alter table tenant_domains add constraint tenant_domains_host_check check (host = lower(host) and host ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$')");
        DB::statement('create unique index tenant_domains_host_unique on tenant_domains (host) where archived_at is null');
        Rls::enable('tenant_domains');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        // BR-04: the public branding of a host, found by its subdomain slug
        // or a verified custom domain of an active tenant. Only the fields a
        // sign-in page shows: the tenant's name, the published tenant-wide
        // theme's look (never its asset ids), the paths of its logos,
        // favicon and background, the welcome text and the BR-07 flag.
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
                        and cd.key = 'default' and cd.scope_type = 'tenant'
                    join public.config_versions v on v.document_id = cd.id and v.status = 'published'
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

            revoke all on function public.app_public_branding(text, text) from public;
            SQL);
        DB::statement("grant execute on function public.app_public_branding(text, text) to {$runtimeRole}");

        // BR-05: the TLS ask endpoint (Caddy on-demand TLS): the tenant of a
        // verified, not archived custom domain of an active tenant.
        // BR-05: the tenants with domains waiting for their DNS check.
        foreach ([
            'app_tenant_for_verified_domain(p_host text)' => <<<'SQL'
                select d.tenant_id from public.tenant_domains d
                join public.tenants t on t.id = d.tenant_id and t.status = 'active'
                where d.host = lower(p_host) and d.status = 'verified' and d.archived_at is null
                SQL,
            'app_tenants_with_pending_domains()' => <<<'SQL'
                select distinct d.tenant_id from public.tenant_domains d
                where d.status = 'pending' and d.archived_at is null
                order by 1
                SQL,
        ] as $signature => $body) {
            $name = strstr($signature, '(', true);
            $types = str_contains($signature, 'p_host') ? 'text' : '';

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
        DB::unprepared('drop function if exists public.app_public_branding(text, text)');
        DB::unprepared('drop function if exists public.app_tenant_for_verified_domain(text)');
        DB::unprepared('drop function if exists public.app_tenants_with_pending_domains()');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('brand_assets');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
