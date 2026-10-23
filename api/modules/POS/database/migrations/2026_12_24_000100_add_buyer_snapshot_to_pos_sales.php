<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// POS-08, POS-10, NFR-08 (owner decision 2026-10-22): a sale keeps its
// buyer's tax identity as sold (`buyer_tax_id`, `buyer_name`), so its
// fiscal documents, refunds and void never read the live party. Both set
// or both null. Nullable columns with no default: no table rewrite. The
// check is added NOT VALID (no scan under the lock) and validated after
// the backfill (2026_12_24_000110).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table pos_sales add column buyer_tax_id varchar(50), add column buyer_name varchar(255)');
        DB::statement(<<<'SQL'
            alter table pos_sales add constraint pos_sales_buyer_check check (
                (buyer_tax_id is null and buyer_name is null) or (buyer_tax_id is not null and buyer_name is not null)
            ) not valid
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table pos_sales drop constraint if exists pos_sales_buyer_check');
        DB::statement('alter table pos_sales drop column if exists buyer_tax_id, drop column if exists buyer_name');
    }
};
