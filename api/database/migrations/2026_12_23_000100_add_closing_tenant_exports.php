<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// EXP-02, NFR-08 (Phase 8 review decision on H2, built as the default): when
// a tenant closes, its full export is built once (`purpose = closing`) and
// each active Owner is emailed a signed link that works without signing in.
// - `purpose`: `request` (an Owner asked, as before) or `closing`.
// - At most one closing export per tenant (a partial unique index).
// - The one-build-at-a-time rule stays for Owners' requests only, so a
//   request still running when the tenant closes never blocks the closing one.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table tenant_exports add column purpose varchar(10) not null default 'request'");
        DB::statement("alter table tenant_exports add constraint tenant_exports_purpose_check check (purpose in ('request', 'closing'))");
        DB::statement("create unique index tenant_exports_one_closing on tenant_exports (tenant_id) where purpose = 'closing'");
        DB::statement('drop index if exists tenant_exports_one_active');
        DB::statement("create unique index tenant_exports_one_active on tenant_exports (tenant_id) where status in ('queued', 'running') and purpose = 'request'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists tenant_exports_one_active');
        DB::statement("create unique index tenant_exports_one_active on tenant_exports (tenant_id) where status in ('queued', 'running')");
        DB::statement('drop index if exists tenant_exports_one_closing');
        DB::statement('alter table tenant_exports drop constraint if exists tenant_exports_purpose_check');
        DB::statement('alter table tenant_exports drop column if exists purpose');
    }
};
