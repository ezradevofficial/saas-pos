<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// TEN-01: only the schema owner creates objects in `public`. The runtime role
// cannot plant a table or function that a security-definer function, or a
// search_path lookup, could pick up.
return new class extends Migration
{
    public function up(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::statement('revoke create on schema public from public');
        DB::statement("revoke create on schema public from {$runtimeRole}");
    }

    public function down(): void
    {
        // Not restored: creating objects in public was never meant to be allowed.
    }
};
