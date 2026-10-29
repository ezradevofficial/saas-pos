<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LAY-01: the quick search's record search matches sales by receipt number. pg_trgm is already
// enabled (2026_10_11_000100); the indexes serve ILIKE and the `~*` patterns of
// SearchTerm. Plain indexes on the searched columns only.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists pos_sales_receipt_number_trgm on pos_sales using gin (receipt_number gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists pos_sales_receipt_number_trgm');
    }
};
