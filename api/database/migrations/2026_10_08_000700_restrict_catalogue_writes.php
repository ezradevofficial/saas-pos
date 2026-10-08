<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// RBAC-01, TEN-01: the permission catalogue and the migrations table are
// global and written only by the schema owner (`permissions:sync` and
// migrations run on pgsql_owner). The runtime role keeps SELECT: a delete
// of a permission cascades to role_has_permissions in every tenant, so a
// runtime write would cross tenants.
return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::statement("revoke insert, update, delete, truncate on permissions, migrations from {$runtimeRole}");
    }

    public function down(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::statement("grant insert, update, delete on permissions, migrations to {$runtimeRole}");
    }
};
