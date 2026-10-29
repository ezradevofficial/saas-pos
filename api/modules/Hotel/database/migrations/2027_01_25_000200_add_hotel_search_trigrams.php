<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LAY-01: the quick search's record search matches reservations by number and guests by name. pg_trgm is already
// enabled (2026_10_11_000100); the indexes serve ILIKE and the `~*` patterns of
// SearchTerm. Plain indexes on the searched columns only.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists hotel_reservations_number_trgm on hotel_reservations using gin (number gin_trgm_ops)');
        DB::statement('create index if not exists hotel_guests_name_trgm on hotel_guests using gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists hotel_reservations_number_trgm');
        DB::statement('drop index if exists hotel_guests_name_trgm');
    }
};
