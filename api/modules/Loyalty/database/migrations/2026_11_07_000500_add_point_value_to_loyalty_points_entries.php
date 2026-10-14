<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B review #7: each points entry keeps the value of a point (minor
// units of the programme currency) it was written with, so its money value
// never changes when the programme's does, live or replayed. Entries
// written before take the programme's value as it is now (the ledger's
// append-only trigger is paused for this one update, inside the
// migration's transaction).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table loyalty_points_entries add column point_value_minor bigint');
        DB::statement('alter table loyalty_points_entries disable trigger loyalty_points_entries_append_only');
        DB::statement('update loyalty_points_entries e set point_value_minor = p.point_value_minor from loyalty_programmes p where p.company_id = e.company_id and p.point_value_minor > 0');
        DB::statement('alter table loyalty_points_entries enable trigger loyalty_points_entries_append_only');
    }

    public function down(): void
    {
        DB::statement('alter table loyalty_points_entries drop column point_value_minor');
    }
};
