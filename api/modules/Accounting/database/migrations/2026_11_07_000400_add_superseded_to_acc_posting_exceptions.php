<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B review #2: a failed daily summary that a later summary of the
// same sales replaced is kept as `superseded` (listed, never retried).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table acc_posting_exceptions drop constraint acc_posting_exceptions_status_check');
        DB::statement("alter table acc_posting_exceptions add constraint acc_posting_exceptions_status_check check (status in ('open', 'resolved', 'superseded'))");
    }

    public function down(): void
    {
        DB::statement('alter table acc_posting_exceptions drop constraint acc_posting_exceptions_status_check');
        DB::statement("alter table acc_posting_exceptions add constraint acc_posting_exceptions_status_check check (status in ('open', 'resolved'))");
    }
};
