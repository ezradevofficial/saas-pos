<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LAY-01: the quick search's record search matches employees by staff number and name. pg_trgm is already
// enabled (2026_10_11_000100); the indexes serve ILIKE and the `~*` patterns of
// SearchTerm. Plain indexes on the searched columns only.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists hr_employees_number_trgm on hr_employees using gin (number gin_trgm_ops)');
        DB::statement('create index if not exists hr_employees_full_name_trgm on hr_employees using gin ((first_name || \' \' || last_name) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists hr_employees_number_trgm');
        DB::statement('drop index if exists hr_employees_full_name_trgm');
    }
};
