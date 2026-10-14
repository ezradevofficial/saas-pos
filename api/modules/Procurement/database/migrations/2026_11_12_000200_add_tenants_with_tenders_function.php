<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR 019, ADR 002 (stage 7B closing review): `procurement:rewrap-keys`
// must reach every tenant holding a tender after an APP_KEY rotation,
// suspended and closed ones included (DueTenants::active skips them, and a
// tender key left on the old key could never be opened once that key is
// dropped). A narrow security-definer function lists their ids only; the
// command then works in each tenant under its own row-level security.
return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_tenders() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct t.tenant_id from public.procurement_tenders t order by 1
            $$;

            revoke all on function public.app_tenants_with_tenders() from public;
            SQL);

        DB::statement("grant execute on function public.app_tenants_with_tenders() to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_tenders()');
    }
};
