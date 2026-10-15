<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// HR-10, ADR 002: `hr:rekey-digests` must reach every tenant holding an
// employee identifier after an HR_DIGEST_KEY rotation, suspended and closed
// ones included (DueTenants::active skips them, and their digests would
// stay on the old key, so uniqueness checks would miss their values once
// they come back). A narrow security-definer function lists their ids
// only; the command then works in each tenant under its own row-level
// security.
return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_hr_identifiers() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct i.tenant_id from public.hr_employee_identifiers i order by 1
            $$;

            revoke all on function public.app_tenants_with_hr_identifiers() from public;
            SQL);

        DB::statement("grant execute on function public.app_tenants_with_hr_identifiers() to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_hr_identifiers()');
    }
};
