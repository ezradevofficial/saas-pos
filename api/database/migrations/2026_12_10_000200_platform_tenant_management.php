<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADM-01, ADM-06 (docs/modules/platform-admin.md):
// - operator_accounts gains what the console searches by (slug, bundle,
//   modules) and the summary of the tenant's Enterprise deal;
// - operator_account_events: each status change of a customer's account,
//   for the revenue reports (trials started and converted, churn). A table
//   of the operator tenant, under row-level security like operator_accounts;
// - subscriptions.status_before_suspension: restored on reactivation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_accounts', function (Blueprint $table) {
            $table->string('slug', 63)->nullable();
            $table->string('bundle', 60)->nullable();
            // The modules the plan covers (its own and its bundle's), for search by module.
            $table->jsonb('modules')->default('[]');
            // ADM-02: the deal in force, {id, effective_from, effective_to}, or null.
            $table->jsonb('deal')->nullable();
        });
        DB::statement('create index operator_accounts_modules_gin on operator_accounts using gin (modules)');

        Schema::create('operator_account_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('customer_tenant_id')->constrained('tenants')->restrictOnDelete();
            // trial_started, subscribed, converted, churned, suspended, reactivated
            $table->string('kind', 20);
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->string('bundle', 60)->nullable();
            // MRR of the account at the change: before it for churn, after it otherwise.
            $table->bigInteger('mrr_minor')->default(0);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'kind', 'occurred_at']);
        });
        DB::statement("alter table operator_account_events add constraint operator_account_events_kind_check check (kind in ('trial_started', 'subscribed', 'converted', 'churned', 'suspended', 'reactivated'))");
        Rls::enable('operator_account_events');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('status_before_suspension', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('status_before_suspension'));
        Schema::dropIfExists('operator_account_events');
        DB::statement('drop index if exists operator_accounts_modules_gin');
        Schema::table('operator_accounts', fn (Blueprint $table) => $table->dropColumn(['slug', 'bundle', 'modules', 'deal']));
    }
};
