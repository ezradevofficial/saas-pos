<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AUTH-11, ADR 023, TEN-01: enterprise single sign-on.
//
// - sso_connections: the tenant's identity providers (Entra ID or Google by
//   OIDC, or SAML 2.0). Public settings in jsonb; the client secret
//   encrypted with the application key.
// - sso_domains: the tenant's email domains, proven by a DNS TXT record as
//   custom domains are (BR-05). Several tenants may claim one; the first to
//   prove it wins (unique among verified, unarchived rows).
// - sso_identities: a user linked to an IdP subject, one live link per
//   connection; unlinking or repointing the connection archives links.
//
// Four owner-owned security-definer functions answer before any tenant is
// known (ADR 002), each returning tenant ids only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // entra | google | saml
            $table->string('provider', 20);
            $table->string('name', 100);
            $table->jsonb('settings')->default('{}');
            $table->text('client_secret')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestampTz('tested_at')->nullable();
            $table->jsonb('last_test')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("alter table sso_connections add constraint sso_connections_provider_check check (provider in ('entra', 'google', 'saml'))");
        Rls::enable('sso_connections');

        Schema::create('sso_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('domain', 253);
            // pending | verified | failed
            $table->string('status', 20)->default('pending');
            $table->string('verification_token', 64);
            $table->timestampTz('pending_since')->useCurrent();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('failure', 40)->nullable();
            $table->smallInteger('missed_checks')->default(0);
            $table->timestampTz('failed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index('domain');
        });

        DB::statement("alter table sso_domains add constraint sso_domains_status_check check (status in ('pending', 'verified', 'failed'))");
        DB::statement("alter table sso_domains add constraint sso_domains_domain_check check (domain = lower(domain) and domain ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$')");
        DB::statement("create unique index sso_domains_verified_domain_unique on sso_domains (domain) where status = 'verified' and archived_at is null");
        Rls::enable('sso_domains');

        Schema::create('sso_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('connection_id');
            $table->string('subject', 255);
            $table->string('email', 255);
            $table->timestampTz('last_sign_in_at')->nullable();
            // Unlinked, or the connection was repointed at another IdP (TEN-06: kept, never deleted).
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'connection_id'])->references(['tenant_id', 'id'])->on('sso_connections')->restrictOnDelete();
            $table->index('user_id');
        });

        // One live link per subject and per user on a connection; archived links stay as history.
        DB::statement('create unique index sso_identities_subject_unique on sso_identities (connection_id, subject) where archived_at is null');
        DB::statement('create unique index sso_identities_user_unique on sso_identities (connection_id, user_id) where archived_at is null');

        Rls::enable('sso_identities');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        foreach ([
            // Email discovery and sign-up's enforced-domain check: the active
            // tenant that proved this email domain.
            'sso_tenant_for_email_domain(p_domain text)' => ['text', <<<'SQL'
                select d.tenant_id from public.sso_domains d
                join public.tenants t on t.id = d.tenant_id and t.status = 'active'
                where d.domain = lower(p_domain) and d.status = 'verified' and d.archived_at is null
                SQL],
            // The sign-in page's host (BR-04): the active tenant whose subdomain
            // slug or verified custom domain it is.
            'sso_tenant_for_host(p_slug text, p_host text)' => ['text, text', <<<'SQL'
                select t.id from public.tenants t
                where t.status = 'active'
                  and ((p_slug is not null and t.slug = lower(p_slug))
                    or (p_host is not null and exists (
                        select 1 from public.tenant_domains d
                        where d.tenant_id = t.id and d.host = lower(p_host)
                          and d.status = 'verified' and d.archived_at is null)))
                order by t.id
                limit 1
                SQL],
            // domains:verify: active tenants with an email domain to check,
            // re-check (verified, unchecked for 20 hours) or archive (failed 7 days).
            'app_tenants_with_sso_domain_checks_due(p_at timestamptz)' => ['timestamptz', <<<'SQL'
                select distinct d.tenant_id from public.sso_domains d
                join public.tenants t on t.id = d.tenant_id and t.status = 'active'
                where d.archived_at is null
                  and (d.status = 'pending'
                    or (d.status = 'verified' and (d.checked_at is null or d.checked_at <= p_at - interval '20 hours'))
                    or (d.status = 'failed' and d.failed_at <= p_at - interval '7 days'))
                order by 1
                SQL],
            // sso:refresh-metadata: active tenants with a SAML connection whose
            // metadata was fetched by URL.
            'app_tenants_with_sso_metadata_urls()' => ['', <<<'SQL'
                select distinct c.tenant_id from public.sso_connections c
                join public.tenants t on t.id = c.tenant_id and t.status = 'active'
                where c.provider = 'saml' and c.archived_at is null and coalesce(c.settings->>'metadata_url', '') <> ''
                order by 1
                SQL],
        ] as $signature => [$types, $body]) {
            $name = strstr($signature, '(', true);

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
        DB::unprepared('drop function if exists public.sso_tenant_for_email_domain(text)');
        DB::unprepared('drop function if exists public.sso_tenant_for_host(text, text)');
        DB::unprepared('drop function if exists public.app_tenants_with_sso_domain_checks_due(timestamptz)');
        DB::unprepared('drop function if exists public.app_tenants_with_sso_metadata_urls()');
        Schema::dropIfExists('sso_identities');
        Schema::dropIfExists('sso_domains');
        Schema::dropIfExists('sso_connections');
    }
};
