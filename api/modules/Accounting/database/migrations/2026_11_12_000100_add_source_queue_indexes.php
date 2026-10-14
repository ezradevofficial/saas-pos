<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ACC-26 (stage 7B performance review): the daily summaries read the sales
// still waiting, per company and day (a partial index over what is not
// summarised yet), and a summary's own sales by its id (retries).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists acc_source_queue_waiting_idx on acc_source_queue (tenant_id, company_id, local_date) where summarised_at is null');
        DB::statement('create index if not exists acc_source_queue_summary_idx on acc_source_queue (company_id, summary_source_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists acc_source_queue_summary_idx');
        DB::statement('drop index if exists acc_source_queue_waiting_idx');
    }
};
