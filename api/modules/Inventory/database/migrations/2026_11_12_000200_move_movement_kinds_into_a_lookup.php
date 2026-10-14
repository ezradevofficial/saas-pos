<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// INV-04 (stage 7B closing review): on a database that ran the inventory
// migrations before the movement kinds became a lookup table, create
// `inventory_movement_kinds` from the kinds the old check allowed, point
// the ledger's `kind` at it and drop the check.
// On a fresh database the base migration made the lookup and the foreign
// key already: nothing changes. Kind rows belong to the migrations that
// insert them, so rolling this back changes nothing.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (! Schema::hasTable('inventory_movement_kinds')) {
            Schema::create('inventory_movement_kinds', function (Blueprint $table) {
                $table->string('kind', 20)->primary();
            });
            $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
            DB::statement("revoke insert, update, delete, truncate on inventory_movement_kinds from {$runtimeRole}");
        }

        $definition = (string) DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'inventory_movements'::regclass and conname = 'inventory_movements_kind_check'");
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);
        // Every row's kind passed the check, so the check's kinds are all the kinds in use.
        DB::table('inventory_movement_kinds')->insertOrIgnore(array_map(fn (string $kind) => ['kind' => $kind], array_values(array_unique($matches[1]))));

        $hasForeignKey = DB::scalar("select count(*) from pg_constraint where conrelid = 'inventory_movements'::regclass and contype = 'f' and confrelid = 'inventory_movement_kinds'::regclass") > 0;

        if (! $hasForeignKey) {
            DB::statement('alter table inventory_movements add constraint inventory_movements_kind_foreign foreign key (kind) references inventory_movement_kinds (kind) on delete restrict');
        }

        DB::statement('alter table inventory_movements drop constraint if exists inventory_movements_kind_check');
    }

    public function down(): void
    {
        // Nothing: the lookup is the schema from now on (see above).
    }
};
