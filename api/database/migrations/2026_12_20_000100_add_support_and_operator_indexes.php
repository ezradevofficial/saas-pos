<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-05 (ForeignKeyIndexTest): two foreign keys of the super-admin tables
// without an index: the operator's account events by customer tenant
// (ADM-02, read per customer) and support grants by their principal user
// (RBAC-13). Both lead with tenant_id; both tables are small.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists operator_account_events_tenant_id_customer_tenant_id_index on operator_account_events (tenant_id, customer_tenant_id)');
        DB::statement('create index if not exists support_access_grants_tenant_id_principal_user_id_index on support_access_grants (tenant_id, principal_user_id) where principal_user_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists operator_account_events_tenant_id_customer_tenant_id_index');
        DB::statement('drop index if exists support_access_grants_tenant_id_principal_user_id_index');
    }
};
