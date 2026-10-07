<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// TEN-04..TEN-06: branch codes unique among a company's active branches,
// unique pairing codes, and the cross-tenant pairing lookup.
return new class extends Migration
{
    public function up(): void
    {
        // An archived branch frees its code (TEN-06).
        DB::statement('alter table branches drop constraint branches_company_id_code_unique');
        DB::statement('create unique index branches_company_id_code_active_unique on branches (company_id, code) where archived_at is null');

        // Unique indexes see every tenant's rows: a code hash names one device.
        DB::statement('create unique index devices_pairing_code_hash_unique on devices (pairing_code_hash) where pairing_code_hash is not null');

        // The tenant of an unexpired pairing code (sha256 hex), across
        // tenants. Runs as the schema owner and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function public.auth_tenant_for_pairing(p_code_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select d.tenant_id from public.devices d
                where d.pairing_code_hash = p_code_hash
                  and d.pairing_code_expires_at > pg_catalog.now()
                limit 1
            $$;

            revoke all on function auth_tenant_for_pairing(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function auth_tenant_for_pairing(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists auth_tenant_for_pairing(text)');
        DB::statement('drop index if exists devices_pairing_code_hash_unique');
        DB::statement('drop index if exists branches_company_id_code_active_unique');
        DB::statement('alter table branches add constraint branches_company_id_code_unique unique (company_id, code)');
    }
};
