<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B review #4: what each discount source's verifier confirmed of a
// line's claim at upload ({source: minor}), so other modules (Accounting)
// treat only that as a promotion and the rest as a manual discount.
// Nullable, no default: a catalogue change only (M1).
// Outside a transaction, each statement commits on its own: the add takes
// its brief lock and releases it before the validation scan, which takes a
// lock that does not block reads or writes.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("set lock_timeout = '5s'");
        DB::statement('alter table pos_sale_lines add column verified_discounts jsonb');
        DB::statement("alter table pos_sale_lines add constraint pos_sale_lines_verified_discounts_check check (verified_discounts is null or jsonb_typeof(verified_discounts) = 'object') not valid");
        DB::statement('alter table pos_sale_lines validate constraint pos_sale_lines_verified_discounts_check');
        DB::statement('reset lock_timeout');
    }

    public function down(): void
    {
        DB::statement('alter table pos_sale_lines drop column verified_discounts');
    }
};
