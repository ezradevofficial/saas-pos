<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NFR-05 (ForeignKeyIndexTest): the marketplace's foreign keys without an
// index (MKT-01..03, API-02): installs by version and by their webhook
// subscription, payables by app, and OAuth codes and tokens by client and
// user. Each leads with tenant_id; the tables are new and small.
return new class extends Migration
{
    private const INDEXES = [
        'app_installations_tenant_id_version_id_index' => 'app_installations (tenant_id, version_id)',
        'app_installations_tenant_id_webhook_subscription_id_index' => 'app_installations (tenant_id, webhook_subscription_id) where webhook_subscription_id is not null',
        'marketplace_payables_tenant_id_app_id_index' => 'marketplace_payables (tenant_id, app_id)',
        'oauth_access_tokens_tenant_id_client_id_index' => 'oauth_access_tokens (tenant_id, client_id)',
        'oauth_auth_codes_tenant_id_client_id_index' => 'oauth_auth_codes (tenant_id, client_id)',
        'oauth_auth_codes_tenant_id_user_id_index' => 'oauth_auth_codes (tenant_id, user_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $on) {
            DB::statement("create index if not exists {$name} on {$on}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("drop index if exists {$name}");
        }
    }
};
