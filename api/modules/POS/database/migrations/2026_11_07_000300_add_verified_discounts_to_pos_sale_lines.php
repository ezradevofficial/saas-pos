<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B review #4: what each discount source's verifier confirmed of a
// line's claim at upload ({source: minor}), so other modules (Accounting)
// treat only that as a promotion and the rest as a manual discount.
// Nullable, no default: a catalogue change only (M1).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table pos_sale_lines add column verified_discounts jsonb');
        DB::statement("alter table pos_sale_lines add constraint pos_sale_lines_verified_discounts_check check (verified_discounts is null or jsonb_typeof(verified_discounts) = 'object') not valid");
        // Every row is null so far: validating only reads, under a lock that does not block reads or writes.
        DB::statement('alter table pos_sale_lines validate constraint pos_sale_lines_verified_discounts_check');
    }

    public function down(): void
    {
        DB::statement('alter table pos_sale_lines drop column verified_discounts');
    }
};
