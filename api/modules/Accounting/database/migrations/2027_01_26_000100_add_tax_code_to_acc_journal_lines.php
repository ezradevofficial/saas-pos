<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ACC-36 (tax returns): a tax line posted from a module's `tax.{tax code}` field keeps the core tax code it came from,
// so the return can total output and input tax per code and rate. Posted lines are immutable (ACC-03): journals posted
// before this column existed keep none and are reported under "no tax code". No new table: the line's row-level
// security is unchanged.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table acc_journal_lines add column if not exists tax_code_id uuid null references tax_codes (id) on delete restrict');
        DB::statement('create index if not exists acc_journal_lines_tax_code_idx on acc_journal_lines (company_id, tax_code_id) where tax_code_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists acc_journal_lines_tax_code_idx');
        DB::statement('alter table acc_journal_lines drop column if exists tax_code_id');
    }
};
