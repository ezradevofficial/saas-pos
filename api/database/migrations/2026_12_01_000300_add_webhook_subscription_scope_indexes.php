<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-05 (ForeignKeyIndexTest): a webhook subscription's company and branch
// filters (HOOK-01) are foreign keys; index them like every other tenant
// key. The table is small, so a plain build inside the migration is fine.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists webhook_subscriptions_tenant_id_company_id_index on webhook_subscriptions (tenant_id, company_id) where company_id is not null');
        DB::statement('create index if not exists webhook_subscriptions_tenant_id_branch_id_index on webhook_subscriptions (tenant_id, branch_id) where branch_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists webhook_subscriptions_tenant_id_company_id_index');
        DB::statement('drop index if exists webhook_subscriptions_tenant_id_branch_id_index');
    }
};
