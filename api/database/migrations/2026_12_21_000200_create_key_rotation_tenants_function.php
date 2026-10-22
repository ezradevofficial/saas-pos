<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-06, ADR 002: `app:reencrypt` (APP_KEY rotation) re-encrypts every
// tenant's encrypted columns under that tenant's row-level security. It
// needs every tenant id, whatever the status (suspended and closed tenants
// still hold encrypted values that must stay readable): a security-definer
// function returning ids only, executable by the runtime role, so the
// command never needs the owner's credentials.
return new class extends Migration
{
    public function up(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_for_key_rotation() returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.id from public.tenants t order by t.id
            $$;
            revoke all on function public.app_tenants_for_key_rotation() from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_for_key_rotation() to {$runtime}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_for_key_rotation()');
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
