<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// WF-02, APR-09: a draft may be discarded. Versions are never deleted (the
// immutability trigger refuses it), so a discarded draft is archived with
// discarded_at/discarded_by set. The published check now accepts an
// archived version that was never published only when it was discarded,
// and a discarded version is archived and was never published (so it is
// never offered for roll back).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table workflow_versions add column discarded_at timestamptz null');
        DB::statement('alter table workflow_versions add column discarded_by uuid null references users (id) on delete restrict');
        DB::statement('alter table workflow_versions drop constraint workflow_versions_published_check');
        DB::statement("alter table workflow_versions add constraint workflow_versions_published_check check (status = 'draft' or published_at is not null or discarded_at is not null)");
        DB::statement("alter table workflow_versions add constraint workflow_versions_discarded_check check (discarded_at is null or (status = 'archived' and published_at is null))");
    }

    public function down(): void
    {
        DB::statement('alter table workflow_versions drop constraint workflow_versions_discarded_check');
        DB::statement('alter table workflow_versions drop constraint workflow_versions_published_check');
        DB::statement("alter table workflow_versions add constraint workflow_versions_published_check check (status = 'draft' or published_at is not null)");
        DB::statement('alter table workflow_versions drop column discarded_by');
        DB::statement('alter table workflow_versions drop column discarded_at');
    }
};
