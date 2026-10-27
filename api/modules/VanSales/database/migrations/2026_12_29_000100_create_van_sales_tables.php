<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Van sales module (docs/modules/van-sales.md). Every table is the tenant's,
// under forced row-level security (TEN-01). Money is bigint minor units with
// its currency (ADR 003); quantities are numeric(18,6) in the item's base
// unit. Ids of runs, visits, collections and counts are made on the device
// (UUID v7). Business records are never deleted (TEN-06): vans, routes and
// stops are archived; collections are voided; ledgers are append-only.
// References to other modules' documents (an Inventory transfer, a POS sale)
// are plain uuids, never foreign keys across modules.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        $user = function (Blueprint $table, string $column, bool $nullable = true): void {
            $nullable ? $table->uuid($column)->nullable() : $table->uuid($column);
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', $column]);
        };

        // VAN-24, VAN-11: a company's GPS and credit settings.
        Schema::create('vs_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('gps_checkin', 10)->default('off');
            $table->smallInteger('gps_retention_days')->default(30);
            $table->boolean('flag_offline_credit')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        DB::statement("alter table vs_settings add constraint vs_settings_check check (gps_checkin in ('off', 'optional') and gps_retention_days between 1 and 90)");
        Rls::enable('vs_settings');

        // VAN-02: a van is a stock location with a rep and a device.
        Schema::create('vs_vans', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('plate', 30)->nullable();
            $user($table, 'rep_id', false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('device_id');
            $table->index(['tenant_id', 'location_id']);
        });
        DB::statement('create unique index vs_vans_location on vs_vans (tenant_id, location_id) where archived_at is null');
        DB::statement('create unique index vs_vans_name on vs_vans (tenant_id, company_id, lower(name)) where archived_at is null');
        Rls::enable('vs_vans');

        // VAN-03: routes.
        Schema::create('vs_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('van_id')->nullable()->constrained('vs_vans')->restrictOnDelete();
            $table->string('name', 120);
            $table->jsonb('visit_days')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('van_id');
        });
        DB::statement('create unique index vs_routes_name on vs_routes (tenant_id, company_id, lower(name)) where archived_at is null');
        Rls::enable('vs_routes');

        // VAN-04: customer stops of a route.
        Schema::create('vs_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('route_id')->constrained('vs_routes')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('parties')->restrictOnDelete();
            $table->smallInteger('position')->default(0);
            $table->jsonb('visit_days')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('route_id');
            $table->index('customer_id');
        });
        DB::statement('create unique index vs_stops_customer on vs_stops (tenant_id, route_id, customer_id) where archived_at is null');
        Rls::enable('vs_stops');

        // VAN-06: a van's selling day.
        Schema::create('vs_runs', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('van_id')->constrained('vs_vans')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('route_id')->nullable()->constrained('vs_routes')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $user($table, 'rep_id', false);
            $table->date('run_date');
            $table->string('status', 10)->default('open');
            $table->timestampTz('started_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('location_id');
            $table->index('route_id');
            $table->index('device_id');
            $table->index(['tenant_id', 'van_id', 'started_at']);
        });
        DB::statement("alter table vs_runs add constraint vs_runs_check check (status in ('open', 'counting', 'closed'))");
        DB::statement("create unique index vs_runs_one_open on vs_runs (tenant_id, van_id) where status = 'open'");
        Rls::enable('vs_runs');

        // VAN-07, VAN-08: load sheets.
        Schema::create('vs_loads', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('van_id')->constrained('vs_vans')->restrictOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('vs_runs')->restrictOnDelete();
            $table->foreignUuid('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUuid('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->uuid('transfer_id')->nullable();
            $table->string('number', 40)->nullable();
            $table->string('state', 12)->default('open');
            $table->text('note')->nullable();
            $user($table, 'requested_by', false);
            $user($table, 'confirmed_by');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('van_id');
            $table->index('run_id');
            $table->index('from_location_id');
            $table->index('to_location_id');
            $table->index(['tenant_id', 'transfer_id']);
        });
        DB::statement("alter table vs_loads add constraint vs_loads_check check (state in ('open', 'confirmed', 'cancelled'))");
        Rls::enable('vs_loads');

        Schema::create('vs_load_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('load_id')->constrained('vs_loads')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->decimal('qty_confirmed', 18, 6)->nullable();
            $table->timestampsTz();

            $table->index('load_id');
            $table->index('item_id');
        });
        DB::statement('alter table vs_load_lines add constraint vs_load_lines_check check (qty > 0 and (qty_confirmed is null or qty_confirmed >= 0))');
        Rls::enable('vs_load_lines');

        // VAN-09: stop visits. Positions are personal data (VAN-24).
        Schema::create('vs_visits', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('run_id')->constrained('vs_runs')->restrictOnDelete();
            $table->foreignUuid('stop_id')->nullable()->constrained('vs_stops')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('parties')->restrictOnDelete();
            $user($table, 'user_id');
            $table->string('status', 10);
            $table->string('no_sale_reason', 12)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->decimal('checkin_lat', 8, 4)->nullable();
            $table->decimal('checkin_lng', 8, 4)->nullable();
            $table->smallInteger('checkin_accuracy_m')->nullable();
            $table->timestampTz('checkin_at')->nullable();
            $table->timestampTz('device_updated_at');
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('run_id');
            $table->index('stop_id');
            $table->index('customer_id');
        });
        DB::statement("alter table vs_visits add constraint vs_visits_check check (status in ('visited', 'sold', 'no_sale', 'skipped') and (no_sale_reason is null or no_sale_reason in ('closed', 'no_need', 'has_stock', 'no_money', 'credit_hold', 'not_found', 'other')))");
        Rls::enable('vs_visits');

        // VAN-10, VAN-11: a van sale linked to its run (one per POS sale).
        Schema::create('vs_sales', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // Null for a sale at a van that has no day to join (flagged `unlinked`, VAN-10).
            $table->foreignUuid('run_id')->nullable()->constrained('vs_runs')->restrictOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('vs_visits')->restrictOnDelete();
            $table->uuid('sale_id');
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->uuid('exchange_for_sale_id')->nullable();
            $table->string('currency', 3);
            $table->bigInteger('total_minor');
            $table->bigInteger('credit_minor')->default(0);
            $table->string('status', 10)->default('completed');
            $table->jsonb('flags')->default('[]');
            $table->string('credit_decision', 8)->nullable();
            $table->bigInteger('limit_minor')->nullable();
            $table->bigInteger('balance_before_minor')->nullable();
            $table->string('review_status', 10)->nullable();
            $user($table, 'reviewed_by');
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('sold_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sale_id']);
            $table->index('company_id');
            $table->index('run_id');
            $table->index('visit_id');
            $table->index('customer_id');
            $table->index(['tenant_id', 'review_status']);
        });
        DB::statement("alter table vs_sales add constraint vs_sales_check check (status in ('completed', 'voided') and (review_status is null or review_status in ('pending', 'reviewed')) and credit_minor >= 0)");
        Rls::enable('vs_sales');

        // VAN-19: quantities sold and taken back per source (base units, signed).
        Schema::create('vs_stock_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('run_id')->constrained('vs_runs')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('source_type', 10);
            $table->uuid('source_id');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_type', 'source_id', 'item_id']);
            $table->index('run_id');
            $table->index('item_id');
        });
        DB::statement("alter table vs_stock_lines add constraint vs_stock_lines_check check (kind in ('sold', 'customer_return') and source_type in ('sale', 'void', 'refund'))");
        Rls::enable('vs_stock_lines');

        // VAN-20: money taken in or given out per run, by kind and currency (signed).
        Schema::create('vs_tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('run_id')->constrained('vs_runs')->restrictOnDelete();
            $table->string('source_type', 16);
            $table->uuid('source_id');
            $table->string('kind', 16);
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_type', 'source_id', 'kind', 'currency']);
            $table->index('run_id');
        });
        DB::statement("alter table vs_tenders add constraint vs_tenders_check check (kind in ('cash', 'mobile_money', 'other') and source_type in ('sale', 'sale_change', 'refund', 'void', 'void_change', 'collection', 'collection_void'))");
        Rls::enable('vs_tenders');

        // VAN-13: the route credit ledger (append-only).
        Schema::create('vs_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('vs_runs')->restrictOnDelete();
            $table->string('kind', 16);
            $table->uuid('source_id');
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'kind', 'source_id', 'currency']);
            $table->index(['tenant_id', 'customer_id', 'currency']);
            $table->index('company_id');
            $table->index('run_id');
        });
        DB::statement("alter table vs_credit_entries add constraint vs_credit_entries_check check (kind in ('sale', 'sale_void', 'collection', 'collection_void', 'refund'))");
        Rls::enable('vs_credit_entries');

        // VAN-17: collections of balances.
        Schema::create('vs_collections', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('run_id')->constrained('vs_runs')->restrictOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('vs_visits')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('method_type', 16);
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('provider_reference', 80)->nullable();
            $table->string('receipt_number', 60);
            $table->integer('receipt_seq');
            $table->uuid('number_range_id')->nullable();
            $table->string('status', 10)->default('recorded');
            $table->jsonb('flags')->default('[]');
            $user($table, 'user_id', false);
            $table->string('void_reason', 500)->nullable();
            $user($table, 'voided_by');
            $table->timestampTz('voided_at')->nullable();
            $table->string('content_digest', 64);
            $table->timestampTz('collected_at');
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number_range_id', 'receipt_seq']);
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('location_id');
            $table->index('device_id');
            $table->index('run_id');
            $table->index('visit_id');
            $table->index('customer_id');
            $table->index('payment_method_id');
        });
        DB::statement("alter table vs_collections add constraint vs_collections_check check (status in ('recorded', 'voided') and amount_minor > 0 and method_type in ('cash', 'mobile_money'))");
        Rls::enable('vs_collections');

        // VAN-18 to VAN-23: end-of-day counts and their reconciliation.
        Schema::create('vs_counts', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('van_id')->constrained('vs_vans')->restrictOnDelete();
            $table->foreignUuid('run_id')->constrained('vs_runs')->restrictOnDelete();
            $table->smallInteger('attempt')->default(1);
            // `count` is the rep's end-of-day count; `unload_shortfall` is what the warehouse found short of the unload (VAN-23).
            $table->string('kind', 20)->default('count');
            $table->jsonb('flags')->default('[]');
            $table->string('status', 10)->default('pending');
            $user($table, 'submitted_by', false);
            $user($table, 'decided_by');
            $table->timestampTz('submitted_at');
            $table->timestampTz('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->smallInteger('stock_variance_lines')->default(0);
            $table->bigInteger('cash_variance_base_minor')->default(0);
            $table->uuid('unload_transfer_id')->nullable();
            $table->timestampTz('written_off_at')->nullable();
            $table->string('content_digest', 64);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'run_id', 'attempt']);
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('van_id');
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("alter table vs_counts add constraint vs_counts_check check (status in ('pending', 'balanced', 'approved', 'rejected') and kind in ('count', 'unload_shortfall'))");
        Rls::enable('vs_counts');

        Schema::create('vs_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('count_id')->constrained('vs_counts')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->decimal('load_qty', 18, 6);
            $table->decimal('sold_qty', 18, 6);
            $table->decimal('returned_qty', 18, 6);
            // What the rep said came back when more than the van held (Inventory), kept beside the figure counted (VAN-19).
            $table->decimal('returned_claimed_qty', 18, 6)->nullable();
            $table->decimal('damaged_qty', 18, 6);
            $table->decimal('variance_qty', 18, 6);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'count_id', 'item_id']);
            $table->index('item_id');
        });
        Rls::enable('vs_count_lines');

        Schema::create('vs_count_tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('count_id')->constrained('vs_counts')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('currency', 3);
            $table->bigInteger('expected_minor');
            $table->bigInteger('counted_minor');
            $table->bigInteger('variance_minor');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'count_id', 'kind', 'currency']);
        });
        Rls::enable('vs_count_tenders');
    }

    public function down(): void
    {
        foreach (['vs_count_tenders', 'vs_count_lines', 'vs_counts', 'vs_collections', 'vs_credit_entries', 'vs_tenders', 'vs_stock_lines', 'vs_sales', 'vs_visits', 'vs_load_lines', 'vs_loads', 'vs_runs', 'vs_stops', 'vs_routes', 'vs_vans', 'vs_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
