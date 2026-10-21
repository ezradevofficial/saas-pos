<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// API-01..API-05, TEN-01: the public API (docs/adr/020-public-api.md).
//
// - users.status `service`: an API key's principal, a user row with no
//   email, phone or usable password (ADR 020: policies typed on User work
//   unchanged). Everything that lists or counts people filters on `active`.
// - tenants.is_sandbox: a developer sandbox (API-05), set when the tenant is
//   inserted and never changed afterwards (trigger), so no tenant can make
//   itself unbilled.
// - catalogue_bundles.api_requests_per_minute: the plan's tenant-wide rate
//   limit (API-03); null falls back to config('api.rate_limits').
// - api_keys: one row per key; only hashes of the secrets are kept, the
//   previous one until the rotation overlap ends.
// - api_key_usage_days: requests per key and UTC day (the back office chart).
// - api_idempotency_keys: the first response to a key's POST with an
//   Idempotency-Key, for 24 hours.
// - api_key_tenant(): the tenant of a key id, across tenants, for the guard
//   (ADR 002: returns the tenant id only).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table users drop constraint users_status_check');
        DB::statement("alter table users add constraint users_status_check check (status in ('pending', 'active', 'deactivated', 'service'))");
        DB::statement('alter table users drop constraint users_email_or_phone_check');
        DB::statement("alter table users add constraint users_email_or_phone_check check (email is not null or phone is not null or status = 'service')");
        // A principal never becomes a person and a person never becomes a principal.
        DB::unprepared(<<<'SQL'
            create or replace function users_service_status_guard() returns trigger
            language plpgsql as $$
            begin
                if (new.status = 'service') is distinct from (old.status = 'service') then
                    raise exception 'a service principal keeps its status' using errcode = 'check_violation';
                end if;
                return new;
            end;
            $$;

            create trigger users_service_status
                before update on users
                for each row execute function users_service_status_guard();
            SQL);

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false);
        });
        DB::unprepared(<<<'SQL'
            create or replace function tenants_sandbox_flag_guard() returns trigger
            language plpgsql as $$
            begin
                if new.is_sandbox is distinct from old.is_sandbox then
                    raise exception 'a tenant''s sandbox flag never changes' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger tenants_sandbox_flag
                before update on tenants
                for each row execute function tenants_sandbox_flag_guard();
            SQL);

        Schema::table('catalogue_bundles', function (Blueprint $table) {
            $table->unsignedInteger('api_requests_per_minute')->nullable();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 100);
            // The key id in `sk_<mode>_<public_id>_<secret>`; unique across tenants.
            $table->string('public_id', 16)->unique();
            $table->string('mode', 4);
            $table->char('secret_hash', 64);
            $table->char('previous_secret_hash', 64)->nullable();
            $table->timestampTz('previous_expires_at')->nullable();
            $table->foreignUuid('principal_id')->unique()->constrained('users')->restrictOnDelete();
            $table->jsonb('scopes')->default('[]');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("alter table api_keys add constraint api_keys_mode_check check (mode in ('live', 'test'))");
        DB::statement("alter table api_keys add constraint api_keys_public_id_check check (public_id ~ '^[a-z0-9]{16}$')");
        DB::statement('alter table api_keys add constraint api_keys_previous_check check ((previous_secret_hash is null) = (previous_expires_at is null))');
        Rls::enable('api_keys');

        Schema::create('api_key_usage_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('api_key_id')->constrained('api_keys')->restrictOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('requests')->default(0);
            $table->timestampsTz();

            $table->unique(['api_key_id', 'day']);
            $table->index(['tenant_id', 'day']);
        });
        Rls::enable('api_key_usage_days');

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('api_key_id')->constrained('api_keys')->restrictOnDelete();
            $table->string('key', 255);
            // sha256 of method, path and body: the same key with another request is refused.
            $table->char('request_hash', 64);
            // Null while the first request runs.
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('body')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['api_key_id', 'key']);
            $table->index(['tenant_id', 'expires_at']);
        });
        Rls::enable('api_idempotency_keys');

        // TEN-01, ADR 002: the tenant of a key id whose tenant's sandbox flag
        // matches the key's prefix. Runs as the schema owner and returns
        // nothing but the id; the guard reads the key under RLS afterwards.
        DB::unprepared(<<<'SQL'
            create or replace function public.api_key_tenant(p_public_id text, p_sandbox boolean) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select k.tenant_id from public.api_keys k
                join public.tenants t on t.id = k.tenant_id
                where k.public_id = p_public_id and t.is_sandbox = p_sandbox
                limit 1
            $$;

            revoke all on function public.api_key_tenant(text, boolean) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.api_key_tenant(text, boolean) to {$runtimeRole}");
        // TEN-06: keys are revoked, never deleted; usage is history.
        DB::statement("revoke delete, truncate on api_keys, api_key_usage_days from {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.api_key_tenant(text, boolean)');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_key_usage_days');
        Schema::dropIfExists('api_keys');
        Schema::table('catalogue_bundles', fn (Blueprint $table) => $table->dropColumn('api_requests_per_minute'));
        DB::statement('drop trigger if exists tenants_sandbox_flag on tenants');
        DB::statement('drop function if exists tenants_sandbox_flag_guard()');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('is_sandbox'));
        DB::statement('drop trigger if exists users_service_status on users');
        DB::statement('drop function if exists users_service_status_guard()');
        DB::statement('alter table users drop constraint users_email_or_phone_check');
        DB::statement('alter table users add constraint users_email_or_phone_check check (email is not null or phone is not null)');
        DB::statement('alter table users drop constraint users_status_check');
        DB::statement("alter table users add constraint users_status_check check (status in ('pending', 'active', 'deactivated'))");
    }
};
