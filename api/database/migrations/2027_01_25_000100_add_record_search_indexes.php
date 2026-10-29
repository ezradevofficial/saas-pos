<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LAY-01: the quick search's record search matches party legal names, phones and emails, item barcodes and subscription invoice numbers. pg_trgm is already
// enabled (2026_10_11_000100); the indexes serve ILIKE and the `~*` patterns of
// SearchTerm. Plain indexes on the searched columns only.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists parties_legal_name_trgm on parties using gin (legal_name gin_trgm_ops)');
        DB::statement('create index if not exists parties_phones_trgm on parties using gin ((phones::text) gin_trgm_ops)');
        DB::statement('create index if not exists parties_emails_trgm on parties using gin ((emails::text) gin_trgm_ops)');
        DB::statement('create index if not exists item_barcodes_barcode_prefix on item_barcodes using btree (barcode text_pattern_ops)');
        DB::statement('create index if not exists billing_invoices_number_trgm on billing_invoices using gin (number gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists parties_legal_name_trgm');
        DB::statement('drop index if exists parties_phones_trgm');
        DB::statement('drop index if exists parties_emails_trgm');
        DB::statement('drop index if exists item_barcodes_barcode_prefix');
        DB::statement('drop index if exists billing_invoices_number_trgm');
    }
};
