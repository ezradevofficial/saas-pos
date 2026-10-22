<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MKT-01..MKT-04, ADM-05, API-02 (OAuth), TEN-01 (docs/adr/024-marketplace.md).
//
// Global, developer-owned (no tenant_id; GlobalTables::MARKETPLACE):
// - marketplace_developers: a developer account, owned by the tenant that
//   registered it (developer_tenant_id), or the platform's own (first party).
// - marketplace_apps: an app and its OAuth client.
// - marketplace_app_secrets: each app's client secret digest and webhook key,
//   with no grant to any runtime role: reached only through the
//   security-definer functions below (security review, ADR 024).
// - marketplace_app_versions: what a tenant consents to (description, logo,
//   redirect URIs, scopes, webhook events, pricing). Only approved versions
//   are listed; an approved version never changes.
// - oauth_clients: Passport's clients, one per app; no secret on the row
//   (`secret` holds the marker `external` for a confidential client).
// Row-level security on these tables keys on developer_tenant_id instead of
// tenant_id: a developer tenant reads and writes its own rows, every tenant
// reads what is listed, and only the platform role reviews (approve,
// reject, suspend). Column grants keep review fields away from developers.
//
// Tenant tables (tenant_id, forced RLS):
// - app_installations: an app installed in a tenant, with what it was
//   consented and its service principal (an api_keys row of kind `app`).
// - oauth_auth_codes, oauth_access_tokens, oauth_refresh_tokens: Passport's
//   tokens, keyed by sha256 of their identifier (never the identifier).
// - marketplace_payables: the developers' share of paid app lines, and the
//   payouts staff mark paid; rows only in the operator tenant (ADR 016).
//
// Security-definer functions (ADR 002; ids only):
// - oauth_token_tenant(kind, id hash): the tenant of an auth code, access or
//   refresh token, for the token endpoint and the resource guard.
// - app_tenants_with_app_installed(app id): tenants with an active install,
//   to tell them when the app is suspended and to roll a webhook secret;
//   answers only the platform role and the app's own developer.
// - oauth_client_secret_ok(client, digest): whether a client secret's sha256
//   matches, without the digest ever leaving the database (boolean).
// - app_install_webhook_key(install): the install's own signing key,
//   HMAC-SHA256(app webhook key, 'install:' || install id), only for an
//   active install of the session's tenant.
// - marketplace_store_app_secrets(app, digest, key): sets an app's secrets,
//   only by its developer's tenant (or the platform role for first-party
//   apps); answers how many rows changed.
// - marketplace_developer_active(developer): whether a developer may serve
//   (not suspended, its tenant not closed).
return new class extends Migration
{
    public function up(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');
        $tenant = Rls::TENANT_EXPRESSION;

        Schema::create('marketplace_developers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null for the platform's own (first-party) developer.
            $table->foreignUuid('developer_tenant_id')->nullable()->unique()->constrained('tenants')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('website', 255)->nullable();
            $table->boolean('first_party')->default(false);
            $table->string('status', 12)->default('active');
            $table->timestampTz('terms_accepted_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table marketplace_developers add constraint marketplace_developers_status_check check (status in ('active', 'suspended'))");
        DB::statement('alter table marketplace_developers add constraint marketplace_developers_owner_check check (first_party = (developer_tenant_id is null))');

        Schema::create('marketplace_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('developer_id')->constrained('marketplace_developers')->restrictOnDelete();
            $table->uuid('developer_tenant_id')->nullable()->index();
            $table->string('slug', 60)->unique();
            // active | suspended (staff, ADM-05) | archived (the developer withdrew it).
            $table->string('status', 12)->default('active');
            $table->uuid('listed_version_id')->nullable();
            $table->uuid('client_id')->unique();
            $table->text('suspended_reason')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->uuid('suspended_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table marketplace_apps add constraint marketplace_apps_status_check check (status in ('active', 'suspended', 'archived'))");
        DB::statement("alter table marketplace_apps add constraint marketplace_apps_slug_check check (slug ~ '^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$')");

        Schema::create('marketplace_app_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('marketplace_apps')->restrictOnDelete();
            $table->uuid('developer_tenant_id')->nullable()->index();
            $table->unsignedInteger('version');
            // draft -> submitted -> approved | rejected; a submitted version may go back to draft (withdrawn).
            $table->string('status', 12)->default('draft');
            $table->string('name', 80);
            $table->string('tagline', 160)->nullable();
            $table->text('description');
            $table->string('category', 20);
            $table->string('logo_path', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('support_email', 255)->nullable();
            $table->string('privacy_url', 255)->nullable();
            $table->jsonb('redirect_uris')->default('[]');
            $table->jsonb('scopes')->default('[]');
            $table->text('webhook_url')->nullable();
            $table->jsonb('webhook_events')->default('[]');
            // {model: free} or {model: paid, monthly: {KES: n, ...}, yearly: {KES: n, ...}} in minor units.
            $table->jsonb('pricing');
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->unique(['app_id', 'version']);
            $table->index(['status', 'submitted_at']);
        });
        DB::statement("alter table marketplace_app_versions add constraint marketplace_app_versions_status_check check (status in ('draft', 'submitted', 'approved', 'rejected'))");
        DB::statement("alter table marketplace_app_versions add constraint marketplace_app_versions_json_check check (jsonb_typeof(redirect_uris) = 'array' and jsonb_typeof(scopes) = 'array' and jsonb_typeof(webhook_events) = 'array' and jsonb_typeof(pricing) = 'object')");
        DB::statement('alter table marketplace_apps add constraint marketplace_apps_listed_fk foreign key (listed_version_id) references marketplace_app_versions (id) on delete restrict');
        // A version can be listed only once approved, and only for its own app.
        DB::unprepared(<<<'SQL'
            create or replace function marketplace_versions_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'app versions are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if new.app_id is distinct from old.app_id or new.version is distinct from old.version
                    or new.developer_tenant_id is distinct from old.developer_tenant_id then
                    raise exception 'a version keeps its app and number' using errcode = 'insufficient_privilege';
                end if;
                if old.status in ('approved', 'rejected') and (new.status is distinct from old.status
                        or row(new.name, new.tagline, new.description, new.category, new.logo_path, new.website, new.support_email, new.privacy_url,
                               new.redirect_uris, new.scopes, new.webhook_url, new.webhook_events, new.pricing)
                           is distinct from row(old.name, old.tagline, old.description, old.category, old.logo_path, old.website, old.support_email, old.privacy_url,
                               old.redirect_uris, old.scopes, old.webhook_url, old.webhook_events, old.pricing)) then
                    raise exception 'a reviewed version never changes: make a new version' using errcode = 'insufficient_privilege';
                end if;
                if old.status = 'submitted' and new.status = 'submitted'
                    and row(new.name, new.tagline, new.description, new.category, new.logo_path, new.website, new.support_email, new.privacy_url,
                            new.redirect_uris, new.scopes, new.webhook_url, new.webhook_events, new.pricing)
                        is distinct from row(old.name, old.tagline, old.description, old.category, old.logo_path, old.website, old.support_email, old.privacy_url,
                            old.redirect_uris, old.scopes, old.webhook_url, old.webhook_events, old.pricing) then
                    raise exception 'a submitted version is under review: withdraw it to edit' using errcode = 'insufficient_privilege';
                end if;
                if new.status is distinct from old.status and not (
                    (old.status = 'draft' and new.status = 'submitted')
                    or (old.status = 'submitted' and new.status in ('draft', 'approved', 'rejected'))) then
                    raise exception 'a version moves draft, submitted, approved or rejected' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger marketplace_versions_guard
                before update or delete on marketplace_app_versions
                for each row execute function marketplace_versions_guard();

            create or replace function marketplace_versions_owner() returns trigger
            language plpgsql as $$
            begin
                if not exists (select 1 from marketplace_apps a where a.id = new.app_id
                        and a.developer_tenant_id is not distinct from new.developer_tenant_id) then
                    raise exception 'a version belongs to its own app''s developer' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger marketplace_versions_owner
                before insert on marketplace_app_versions
                for each row execute function marketplace_versions_owner();

            create or replace function marketplace_apps_listed_guard() returns trigger
            language plpgsql as $$
            begin
                if new.listed_version_id is not null and new.listed_version_id is distinct from old.listed_version_id
                    and not exists (select 1 from marketplace_app_versions v where v.id = new.listed_version_id and v.app_id = new.id and v.status = 'approved') then
                    raise exception 'only an approved version of the app is listed' using errcode = 'check_violation';
                end if;
                if new.developer_id is distinct from old.developer_id or new.developer_tenant_id is distinct from old.developer_tenant_id
                    or new.client_id is distinct from old.client_id or new.slug is distinct from old.slug then
                    raise exception 'an app keeps its developer, client and slug' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger marketplace_apps_listed_guard
                before update on marketplace_apps
                for each row execute function marketplace_apps_listed_guard();
            SQL);

        // Passport's clients (laravel/passport 13 schema), one per app; global.
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableUuidMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->uuid('app_id')->nullable()->unique();
            $table->uuid('developer_tenant_id')->nullable()->index();
            $table->timestampsTz();
        });

        // ---- Row-level security on the global marketplace tables -------------------------------------------------
        foreach (['marketplace_developers', 'marketplace_apps', 'marketplace_app_versions', 'oauth_clients'] as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
            DB::statement("revoke all on {$table} from {$runtime}");
            DB::statement("grant select on {$table} to {$platform}");
            DB::statement("create policy platform_read on {$table} for select to {$platform} using (true)");
        }

        // Developers: their own account; every tenant reads the developer of a listed app.
        DB::statement("grant select, insert on marketplace_developers to {$runtime}");
        DB::statement("grant update (name, email, website, terms_accepted_at, updated_at) on marketplace_developers to {$runtime}");
        DB::statement("grant update (status, updated_at) on marketplace_developers to {$platform}");
        DB::statement("create policy developer_own on marketplace_developers for select to {$runtime} using (developer_tenant_id = {$tenant}
            or exists (select 1 from marketplace_apps a where a.developer_id = marketplace_developers.id and a.listed_version_id is not null))");
        DB::statement("create policy developer_insert on marketplace_developers for insert to {$runtime} with check (developer_tenant_id = {$tenant} and first_party = false and status = 'active')");
        DB::statement("create policy developer_update on marketplace_developers for update to {$runtime} using (developer_tenant_id = {$tenant}) with check (developer_tenant_id = {$tenant})");
        DB::statement("create policy platform_update on marketplace_developers for update to {$platform} using (true) with check (true)");

        // Apps: the developer's own, and every app that has been listed.
        DB::statement("grant select, insert on marketplace_apps to {$runtime}");
        DB::statement("grant update (archived_at, status, updated_at) on marketplace_apps to {$runtime}");
        // Review and suspension.
        DB::statement("grant update (status, listed_version_id, suspended_reason, suspended_at, suspended_by, updated_at) on marketplace_apps to {$platform}");
        DB::statement("create policy app_read on marketplace_apps for select to {$runtime} using (developer_tenant_id = {$tenant} or listed_version_id is not null)");
        DB::statement("create policy app_insert on marketplace_apps for insert to {$runtime} with check (developer_tenant_id = {$tenant} and status = 'active' and listed_version_id is null
            and exists (select 1 from marketplace_developers d where d.id = developer_id and d.developer_tenant_id = {$tenant}))");
        // A developer archives (or restores) only an app that is not suspended; suspension is the platform's.
        DB::statement("create policy app_update on marketplace_apps for update to {$runtime} using (developer_tenant_id = {$tenant} and status <> 'suspended') with check (developer_tenant_id = {$tenant} and status in ('active', 'archived'))");
        DB::statement("create policy platform_update on marketplace_apps for update to {$platform} using (true) with check (true)");

        // Versions: the developer's own; approved versions are public. A developer moves a version only between draft and submitted.
        DB::statement("grant select, insert on marketplace_app_versions to {$runtime}");
        DB::statement("grant update (status, name, tagline, description, category, logo_path, website, support_email, privacy_url, redirect_uris, scopes, webhook_url, webhook_events, pricing, submitted_at, submitted_by, updated_at) on marketplace_app_versions to {$runtime}");
        DB::statement("grant update (status, reviewed_at, reviewed_by, review_note, updated_at) on marketplace_app_versions to {$platform}");
        DB::statement("create policy version_read on marketplace_app_versions for select to {$runtime} using (developer_tenant_id = {$tenant} or status = 'approved')");
        DB::statement("create policy version_insert on marketplace_app_versions for insert to {$runtime} with check (developer_tenant_id = {$tenant} and status = 'draft'
            and exists (select 1 from marketplace_apps a where a.id = app_id and a.developer_tenant_id = {$tenant}))");
        DB::statement("create policy version_update on marketplace_app_versions for update to {$runtime} using (developer_tenant_id = {$tenant} and status in ('draft', 'submitted')) with check (developer_tenant_id = {$tenant} and status in ('draft', 'submitted'))");
        DB::statement("create policy platform_update on marketplace_app_versions for update to {$platform} using (status = 'submitted') with check (status in ('approved', 'rejected'))");

        // Clients: read by the token endpoint (no tenant yet) and the consent screen only for listed apps, and by their
        // own developer; created by their developer for its own app; the redirect URIs and revocation are review's.
        DB::statement("grant select, insert on oauth_clients to {$runtime}");
        DB::statement("grant update (name, updated_at) on oauth_clients to {$runtime}");
        DB::statement("grant update (redirect_uris, secret, revoked, updated_at) on oauth_clients to {$platform}");
        DB::statement("create policy client_read on oauth_clients for select to {$runtime} using (developer_tenant_id = {$tenant}
            or exists (select 1 from marketplace_apps a where a.id = oauth_clients.app_id and a.listed_version_id is not null))");
        DB::statement("create policy client_insert on oauth_clients for insert to {$runtime} with check (developer_tenant_id = {$tenant}
            and exists (select 1 from marketplace_apps a where a.id = app_id and a.developer_tenant_id = {$tenant}))");
        DB::statement("create policy client_update on oauth_clients for update to {$runtime} using (developer_tenant_id = {$tenant}) with check (developer_tenant_id = {$tenant})");
        DB::statement("create policy platform_update on oauth_clients for update to {$platform} using (true) with check (true)");

        // ---- App secrets: no grant to any runtime role (security review, ADR 024) ---------------------------------
        Schema::create('marketplace_app_secrets', function (Blueprint $table) {
            $table->foreignUuid('app_id')->primary()->constrained('marketplace_apps')->restrictOnDelete();
            // sha256 (hex) of the client secret; null for a public client or one not set up yet.
            $table->char('client_secret_sha256', 64)->nullable();
            // The app's webhook key (32 random bytes); installs sign with keys derived from it.
            $table->binary('webhook_key');
            $table->timestampsTz();
        });
        DB::statement("revoke all on marketplace_app_secrets from {$runtime}");
        DB::statement("revoke all on marketplace_app_secrets from {$platform}");

        // ---- Tenant tables -------------------------------------------------------------------------------------
        Schema::table('api_keys', function (Blueprint $table) {
            // `key` (Settings, API keys) or `app` (a marketplace install's principal, managed through its install).
            $table->string('kind', 4)->default('key');
        });
        DB::statement("alter table api_keys add constraint api_keys_kind_check check (kind in ('key', 'app'))");

        Schema::create('app_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('app_id')->constrained('marketplace_apps')->restrictOnDelete();
            // The version consented to: its scopes and webhook events are what the app may use here.
            $table->foreignUuid('version_id')->constrained('marketplace_app_versions')->restrictOnDelete();
            $table->foreignUuid('api_key_id')->unique()->constrained('api_keys')->restrictOnDelete();
            $table->foreignUuid('principal_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('active');
            $table->jsonb('scopes');
            $table->jsonb('webhook_events')->default('[]');
            $table->foreignUuid('webhook_subscription_id')->nullable()->constrained('webhook_subscriptions')->restrictOnDelete();
            // What the tenant pays (MKT-03), from the consented version: {currency, monthly_minor, yearly_minor} or null (free).
            $table->jsonb('price')->nullable();
            $table->foreignUuid('installed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('installed_at');
            $table->foreignUuid('consented_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('consented_at');
            $table->foreignUuid('uninstalled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('uninstalled_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });
        DB::statement("alter table app_installations add constraint app_installations_status_check check (status in ('active', 'uninstalled'))");
        DB::statement("create unique index app_installations_one_active on app_installations (tenant_id, app_id) where status = 'active'");
        Rls::enable('app_installations');

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            // sha256 of the code's identifier (the identifier itself is only inside the encrypted code).
            $table->char('id', 64)->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestampTz('expires_at')->nullable();
        });
        Rls::enable('oauth_auth_codes');

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            // sha256 of the token's `jti`.
            $table->char('id', 64)->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestampsTz();
            $table->timestampTz('expires_at')->nullable();

            $table->index(['tenant_id', 'user_id', 'client_id']);
        });
        Rls::enable('oauth_access_tokens');

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->tenantId();
            $table->char('access_token_id', 64)->index();
            $table->boolean('revoked');
            $table->timestampTz('expires_at')->nullable();
        });
        Rls::enable('oauth_refresh_tokens');

        Schema::create('marketplace_payables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('developer_id')->constrained('marketplace_developers')->restrictOnDelete();
            $table->foreignUuid('app_id')->nullable()->constrained('marketplace_apps')->restrictOnDelete();
            // earning (the developer's share of a paid app line) or payout (staff paid it out).
            $table->string('kind', 8);
            $table->char('currency', 3);
            // Always positive: an earning adds to the balance, a payout takes from it.
            $table->unsignedBigInteger('amount_minor');
            // Earnings: the line's amount net of discount and tax, and the share applied.
            $table->unsignedBigInteger('base_minor')->nullable();
            $table->unsignedSmallInteger('share_percent')->nullable();
            $table->foreignUuid('operator_invoice_id')->nullable()->constrained('operator_invoices')->restrictOnDelete();
            $table->uuid('customer_tenant_id')->nullable();
            $table->unsignedSmallInteger('line_no')->nullable();
            // Payouts: the bank or mobile money reference, and the staff member who recorded it.
            $table->string('reference', 100)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'developer_id', 'currency']);
        });
        DB::statement("alter table marketplace_payables add constraint marketplace_payables_kind_check check (kind in ('earning', 'payout'))");
        DB::statement("alter table marketplace_payables add constraint marketplace_payables_shape_check check ((kind = 'earning' and operator_invoice_id is not null and line_no is not null) or (kind = 'payout' and reference is not null and recorded_by is not null))");
        DB::statement("create unique index marketplace_payables_one_per_line on marketplace_payables (operator_invoice_id, line_no) where kind = 'earning'");
        Rls::enable('marketplace_payables');

        // TEN-06: installs are uninstalled, the ledger is append-only; neither is deleted. Tokens are revoked, not deleted.
        DB::statement("revoke delete, truncate on app_installations from {$runtime}");
        DB::statement("revoke update, delete, truncate on marketplace_payables from {$runtime}");

        // Webhook subscriptions of an app: switched off when it is uninstalled or suspended.
        DB::statement('alter table webhook_subscriptions drop constraint webhook_subscriptions_disabled_check');
        DB::statement("alter table webhook_subscriptions add constraint webhook_subscriptions_disabled_check check (disabled_reason is null or disabled_reason in ('failing', 'uninstalled', 'app_suspended'))");

        // ---- Security-definer lookups (ADR 002: ids only) -------------------------------------------------------
        DB::unprepared(str_replace('__PLATFORM__', str_replace("'", "''", config('database.connections.pgsql_platform.username') ?: 'app_platform'), <<<'SQL'
            create or replace function public.oauth_token_tenant(p_kind text, p_id_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select case p_kind
                    when 'auth_code' then (select c.tenant_id from public.oauth_auth_codes c where c.id = p_id_hash)
                    when 'access_token' then (select t.tenant_id from public.oauth_access_tokens t where t.id = p_id_hash)
                    when 'refresh_token' then (select r.tenant_id from public.oauth_refresh_tokens r where r.id = p_id_hash)
                end
            $$;

            revoke all on function public.oauth_token_tenant(text, text) from public;

            create or replace function public.app_tenants_with_app_installed(p_app_id uuid) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct i.tenant_id from public.app_installations i
                join public.tenants t on t.id = i.tenant_id
                join public.marketplace_apps a on a.id = i.app_id
                where i.app_id = p_app_id and i.status = 'active' and t.status <> 'closed'
                  and (session_user = '__PLATFORM__'
                       or a.developer_tenant_id = nullif(pg_catalog.current_setting('app.tenant_id', true), '')::uuid)
                order by 1
            $$;

            revoke all on function public.app_tenants_with_app_installed(uuid) from public;
            SQL));

        DB::unprepared(str_replace('__PLATFORM__', str_replace("'", "''", config('database.connections.pgsql_platform.username') ?: 'app_platform'), <<<'SQL'
            -- HMAC-SHA256 with the built-in sha256() (no extension needed). Not a security definer; runs as its caller.
            create or replace function public.marketplace_hmac_sha256(p_key bytea, p_message bytea) returns bytea
            language plpgsql immutable strict
            set search_path = pg_catalog, public
            as $$
            declare
                k bytea := p_key;
                ipad bytea;
                opad bytea;
            begin
                if length(k) > 64 then
                    k := sha256(k);
                end if;
                k := k || decode(repeat('00', 64 - length(k)), 'hex');
                ipad := k;
                opad := k;
                for i in 0..63 loop
                    ipad := set_byte(ipad, i, get_byte(k, i) # 54);
                    opad := set_byte(opad, i, get_byte(k, i) # 92);
                end loop;
                return sha256(opad || sha256(ipad || p_message));
            end;
            $$;

            revoke all on function public.marketplace_hmac_sha256(bytea, bytea) from public;

            create or replace function public.oauth_client_secret_ok(p_client_id uuid, p_secret_sha256 text) returns boolean
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select coalesce((select s.client_secret_sha256 = lower(p_secret_sha256)
                    from public.marketplace_app_secrets s
                    join public.oauth_clients c on c.app_id = s.app_id
                    where c.id = p_client_id and s.client_secret_sha256 is not null), false)
            $$;

            revoke all on function public.oauth_client_secret_ok(uuid, text) from public;

            create or replace function public.app_install_webhook_key(p_installation_id uuid) returns text
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select 'whsec_' || pg_catalog.encode(public.marketplace_hmac_sha256(s.webhook_key, pg_catalog.convert_to('install:' || i.id::text, 'UTF8')), 'base64')
                from public.app_installations i
                join public.marketplace_app_secrets s on s.app_id = i.app_id
                where i.id = p_installation_id and i.status = 'active'
                  and i.tenant_id = nullif(pg_catalog.current_setting('app.tenant_id', true), '')::uuid
            $$;

            revoke all on function public.app_install_webhook_key(uuid) from public;

            create or replace function public.marketplace_store_app_secrets(p_app_id uuid, p_client_secret_sha256 text, p_webhook_key bytea) returns integer
            language plpgsql volatile security definer
            set search_path = pg_catalog, public
            as $$
            declare
                allowed boolean;
                changed integer;
            begin
                select (a.developer_tenant_id is not null and a.developer_tenant_id = nullif(pg_catalog.current_setting('app.tenant_id', true), '')::uuid)
                    or (a.developer_tenant_id is null and session_user = '__PLATFORM__')
                into allowed from public.marketplace_apps a where a.id = p_app_id;

                if allowed is not true then
                    return 0;
                end if;

                insert into public.marketplace_app_secrets (app_id, client_secret_sha256, webhook_key, created_at, updated_at)
                values (p_app_id, lower(p_client_secret_sha256), coalesce(p_webhook_key, pg_catalog.sha256(pg_catalog.convert_to(pg_catalog.gen_random_uuid()::text, 'UTF8'))), now(), now())
                on conflict (app_id) do update set
                    client_secret_sha256 = coalesce(lower(excluded.client_secret_sha256), public.marketplace_app_secrets.client_secret_sha256),
                    webhook_key = case when p_webhook_key is null then public.marketplace_app_secrets.webhook_key else excluded.webhook_key end,
                    updated_at = now();
                get diagnostics changed = row_count;

                return changed;
            end;
            $$;

            revoke all on function public.marketplace_store_app_secrets(uuid, text, bytea) from public;

            create or replace function public.marketplace_developer_active(p_developer_id uuid) returns boolean
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select coalesce((select d.status = 'active' and (d.developer_tenant_id is null or t.status <> 'closed')
                    from public.marketplace_developers d
                    left join public.tenants t on t.id = d.developer_tenant_id
                    where d.id = p_developer_id), false)
            $$;

            revoke all on function public.marketplace_developer_active(uuid) from public;
            SQL));

        DB::statement("grant execute on function public.oauth_token_tenant(text, text) to {$runtime}");
        DB::statement("grant execute on function public.app_tenants_with_app_installed(uuid) to {$runtime}, {$platform}");
        DB::statement("grant execute on function public.oauth_client_secret_ok(uuid, text) to {$runtime}");
        DB::statement("grant execute on function public.app_install_webhook_key(uuid) to {$runtime}");
        DB::statement("grant execute on function public.marketplace_store_app_secrets(uuid, text, bytea) to {$runtime}, {$platform}");
        DB::statement("grant execute on function public.marketplace_developer_active(uuid) to {$runtime}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_app_installed(uuid)');
        DB::statement('drop function if exists public.marketplace_developer_active(uuid)');
        DB::statement('drop function if exists public.marketplace_store_app_secrets(uuid, text, bytea)');
        DB::statement('drop function if exists public.app_install_webhook_key(uuid)');
        DB::statement('drop function if exists public.oauth_client_secret_ok(uuid, text)');
        DB::statement('drop function if exists public.marketplace_hmac_sha256(bytea, bytea)');
        DB::statement('drop function if exists public.oauth_token_tenant(text, text)');
        DB::statement('alter table webhook_subscriptions drop constraint webhook_subscriptions_disabled_check');
        DB::statement("alter table webhook_subscriptions add constraint webhook_subscriptions_disabled_check check (disabled_reason is null or disabled_reason in ('failing'))");
        Schema::dropIfExists('marketplace_payables');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('app_installations');
        DB::statement('alter table api_keys drop constraint api_keys_kind_check');
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::dropIfExists('marketplace_app_secrets');
        Schema::dropIfExists('oauth_clients');
        DB::statement('alter table marketplace_apps drop constraint marketplace_apps_listed_fk');
        Schema::dropIfExists('marketplace_app_versions');
        Schema::dropIfExists('marketplace_apps');
        Schema::dropIfExists('marketplace_developers');
        DB::statement('drop function if exists marketplace_versions_guard()');
        DB::statement('drop function if exists marketplace_apps_listed_guard()');
        DB::statement('drop function if exists marketplace_versions_owner()');
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
