<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// API-01..API-05 review (docs/adr/020-public-api.md):
//
// - api_idempotency_keys.locked_until: a claim (null status) is held 60 s;
//   after that another request with the same key may take it over.
// - app_prune_api_idempotency_keys(): deletes expired stored responses of
//   every tenant for the scheduled prune; returns only how many (ADR 002).
// - app_tenants_with_api_keys(): tenants with a key not revoked, for the
//   usage flush (`api:flush-usage`).
// - app_sandbox_tenant_ids(): active developer sandboxes, for `sandbox:expire`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->timestampTz('locked_until')->nullable();
        });

        DB::unprepared(<<<'SQL'
            create or replace function public.app_prune_api_idempotency_keys(p_before timestamptz) returns integer
            language plpgsql volatile security definer
            set search_path = pg_catalog, public
            as $$
            declare
                deleted integer;
            begin
                delete from public.api_idempotency_keys where expires_at < p_before;
                get diagnostics deleted = row_count;
                return deleted;
            end;
            $$;

            revoke all on function public.app_prune_api_idempotency_keys(timestamptz) from public;

            create or replace function public.app_tenants_with_api_keys() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct k.tenant_id from public.api_keys k
                join public.tenants t on t.id = k.tenant_id
                where k.revoked_at is null and t.status = 'active'
                order by 1
            $$;

            revoke all on function public.app_tenants_with_api_keys() from public;

            create or replace function public.app_sandbox_tenant_ids() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.id from public.tenants t
                where t.is_sandbox and t.status = 'active'
                order by 1
            $$;

            revoke all on function public.app_sandbox_tenant_ids() from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.app_prune_api_idempotency_keys(timestamptz) to {$runtimeRole}");
        DB::statement("grant execute on function public.app_tenants_with_api_keys() to {$runtimeRole}");
        DB::statement("grant execute on function public.app_sandbox_tenant_ids() to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_sandbox_tenant_ids()');
        DB::statement('drop function if exists public.app_tenants_with_api_keys()');
        DB::statement('drop function if exists public.app_prune_api_idempotency_keys(timestamptz)');
        Schema::table('api_idempotency_keys', fn (Blueprint $table) => $table->dropColumn('locked_until'));
    }
};
