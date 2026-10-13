<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-02, IMP-03: finding an item by code (case-insensitive) used to read
// every item of the tenant: under row-level security the planner may only
// use leakproof comparisons in index conditions, and citext's `=` (or
// lower() on the column) is not. `code_key` stores lower(code) once, so a
// lookup is a plain text `=` (leakproof) against an index: checking codes
// stays fast with 50,000 items and during large imports.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table items add column code_key text generated always as (lower(code::text)) stored');
        DB::statement('create index items_code_key on items (code_key, company_id) where archived_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists items_code_key');
        DB::statement('alter table items drop column if exists code_key');
    }
};
