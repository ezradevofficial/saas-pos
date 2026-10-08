<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-04, ADR 004: device sync hands out only changes of transactions older
// than the oldest one still running, cluster wide. A session left idle
// inside a transaction would hold every device's changes back, so no
// session of this database may stay idle in a transaction for more than a
// minute. The runtime connection also sets it, with a statement timeout,
// through `server_options` (config/database.php). Set on the database: the
// schema owner may not change other roles' settings (no CREATEROLE).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            do $$ begin
                execute format('alter database %I set idle_in_transaction_session_timeout = %L', current_database(), '60s');
            end $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            do $$ begin
                execute format('alter database %I reset idle_in_transaction_session_timeout', current_database());
            end $$;
            SQL);
    }
};
