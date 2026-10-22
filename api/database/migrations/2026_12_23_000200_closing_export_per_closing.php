<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-08, EXP-02: one closing export per closing, not per tenant forever. A
// closing export records when its tenant closed (`closed_at`, the tenant's
// updated_at as the status became closed); the unique index is on the tenant
// and that time. A tenant reopened and closed again gets a fresh export, and
// the listener running twice for one closing never builds a second.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table tenant_exports add column closed_at timestamptz');
        // Closing exports made before this migration: their tenant's closing time is not known; their creation stands in.
        DB::statement("update tenant_exports set closed_at = created_at where purpose = 'closing'");
        DB::statement("alter table tenant_exports add constraint tenant_exports_closed_at_check check ((purpose = 'closing') = (closed_at is not null))");
        DB::statement('drop index if exists tenant_exports_one_closing');
        DB::statement("create unique index tenant_exports_one_closing on tenant_exports (tenant_id, closed_at) where purpose = 'closing'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists tenant_exports_one_closing');
        DB::statement('alter table tenant_exports drop constraint if exists tenant_exports_closed_at_check');
        DB::statement('alter table tenant_exports drop column if exists closed_at');
        DB::statement("create unique index tenant_exports_one_closing on tenant_exports (tenant_id) where purpose = 'closing'");
    }
};
