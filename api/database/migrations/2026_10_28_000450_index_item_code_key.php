<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-02, IMP-03: the index behind code lookups (Item::scopeWhereCode), built
// CONCURRENTLY so a large items table stays writable while it builds; so
// this migration runs outside a transaction. Tenant first, as RLS filters
// on it, then the sharing scope and the code.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('create index concurrently if not exists items_code_key on items (tenant_id, company_id, code_key) where archived_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists items_code_key');
    }
};
