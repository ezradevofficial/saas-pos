<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fixed assets module (docs/modules/fixed-assets.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Money is bigint
// minor units next to its currency; book figures are in the company's base
// currency; rates numeric(18,8) through the fxSnapshot columns (ADR 003,
// CUR-04); meter readings numeric(20,6). Users are referenced by
// (tenant_id, id), so a row never points at another tenant's user. An HR
// employee custodian is a plain id (HR's table may not exist; its read
// contract answers for it). Records are never deleted (TEN-06).
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };
        $currency = function (Blueprint $table, string $column, bool $nullable = false): void {
            $definition = $table->char($column, 3);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
        };
        $check = fn (string $table, string $name, string $condition) => DB::statement("alter table {$table} add constraint {$table}_{$name}_check check ({$condition})");

        // FA-01: the pro-rata convention and the tax book, per company.
        Schema::create('fa_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('convention', 12)->default('days');
            $table->boolean('tax_book')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        $check('fa_settings', 'convention', "convention in ('days', 'mid_month')");
        Rls::enable('fa_settings');

        // FA-02: categories and their default depreciation terms.
        Schema::create('fa_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('method', 30);
            $table->integer('useful_life_months')->nullable();
            $table->decimal('residual_percent', 7, 4)->default(0);
            $table->decimal('declining_rate', 7, 4)->nullable();
            $table->string('tax_class', 60)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'code']);
        });
        $check('fa_categories', 'terms', "method in ('straight_line', 'declining_balance', 'units_of_production', 'none')
            and (useful_life_months is null or useful_life_months between 1 and 1200)
            and residual_percent between 0 and 100 and (declining_rate is null or declining_rate > 0 and declining_rate <= 100)");
        Rls::enable('fa_categories');

        // FA-21: items flagged as assets, with their default category.
        Schema::create('fa_item_categories', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('fa_categories')->restrictOnDelete();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('create unique index fa_item_categories_active_unique on fa_item_categories (tenant_id, company_id, item_id) where archived_at is null');
        Rls::enable('fa_item_categories');

        // FA-04, FA-05, FA-11: the register.
        Schema::create('fa_assets', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('fa_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 12);
            $userRef($table, 'custodian_user_id');
            // HR's employee, through HR\Contracts\EmployeeDirectory (no foreign key: another module's table).
            $table->uuid('custodian_employee_id')->nullable();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('serial_number', 120)->nullable();
            $table->date('acquired_on')->nullable();
            $table->date('in_service_on')->nullable();
            $currency($table, 'currency');
            $table->bigInteger('cost_minor')->default(0);
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->bigInteger('base_cost_minor')->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('supplier_reference', 120)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('warranty_note', 500)->nullable();
            // Depreciation terms (FA-07 to FA-11), copied from the category and editable.
            $table->string('method', 30)->nullable();
            $table->integer('useful_life_months')->nullable();
            $table->bigInteger('residual_minor')->default(0);
            $table->decimal('declining_rate', 7, 4)->nullable();
            $table->decimal('total_units', 20, 6)->nullable();
            $table->decimal('meter_start', 20, 6)->default(0);
            // The book, in base minor units.
            $table->bigInteger('book_cost_minor')->default(0);
            $table->bigInteger('accumulated_minor')->default(0);
            $table->bigInteger('impairment_minor')->default(0);
            $table->bigInteger('surplus_minor')->default(0);
            $table->bigInteger('revaluation_loss_minor')->default(0);
            $table->date('depreciated_to')->nullable();
            // FA-24: an opening register asset (posts no capitalisation).
            $table->boolean('opening')->default(false);
            // FA-21: where a draft came from (unique per source line, so an event replayed creates nothing new).
            $table->string('source', 30)->default('manual');
            $table->uuid('source_id')->nullable();
            $table->uuid('source_line_id')->nullable();
            $table->integer('source_unit')->nullable();
            $table->uuid('order_line_id')->nullable();
            $table->jsonb('custom')->nullable();
            $userRef($table, 'created_by');
            $userRef($table, 'activated_by');
            $table->timestampTz('activated_at')->nullable();
            $table->date('disposed_on')->nullable();
            $userRef($table, 'archived_by');
            $table->timestampTz('archived_at')->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'order_line_id']);
        });
        $check('fa_assets', 'terms', "status in ('draft', 'active', 'disposed')
            and source in ('manual', 'import', 'procurement_receipt', 'procurement_invoice')
            and (method is null or method in ('straight_line', 'declining_balance', 'units_of_production', 'none'))
            and cost_minor >= 0 and residual_minor >= 0 and accumulated_minor >= 0 and impairment_minor >= 0 and surplus_minor >= 0 and revaluation_loss_minor >= 0
            and (custodian_user_id is null or custodian_employee_id is null)
            and (useful_life_months is null or useful_life_months between 1 and 1200)
            and (total_units is null or total_units > 0)");
        DB::statement('create unique index fa_assets_number_unique on fa_assets (tenant_id, company_id, number)');
        DB::statement('create unique index fa_assets_source_unique on fa_assets (tenant_id, source, source_line_id, source_unit) where source_line_id is not null');
        DB::statement('create index fa_assets_custom on fa_assets using gin (custom jsonb_path_ops)');
        Rls::enable('fa_assets');

        // FA-06: documents attached to an asset.
        Schema::create('fa_asset_documents', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('mime', 120);
            $table->bigInteger('size');
            $table->string('disk', 30);
            $table->string('path', 500);
            $userRef($table, 'uploaded_by', nullable: false);
            $userRef($table, 'archived_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
        });
        Rls::enable('fa_asset_documents');

        // FA-26: each asset's history (estimates, moves, revaluations, readings, maintenance).
        Schema::create('fa_asset_events', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->string('kind', 30);
            $table->date('happened_on');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('document_type', 30)->nullable();
            $table->uuid('document_id')->nullable();
            $table->text('note')->nullable();
            $userRef($table, 'user_id');
            $table->timestampTz('created_at', 6);

            $table->index(['tenant_id', 'asset_id', 'created_at']);
        });
        Rls::enable('fa_asset_events');

        // FA-12, FA-13: monthly depreciation runs and their lines.
        Schema::create('fa_depreciation_runs', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->date('period_end');
            $table->string('status', 12);
            $table->string('convention', 12);
            $currency($table, 'base_currency');
            $table->bigInteger('total_minor');
            $table->integer('assets_count');
            $userRef($table, 'posted_by', nullable: false);
            $table->timestampTz('posted_at');
            $userRef($table, 'reversed_by');
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'period']);
        });
        $check('fa_depreciation_runs', 'status', "status in ('posted', 'reversed') and period_end >= period and total_minor >= 0");
        DB::statement("create unique index fa_depreciation_runs_posted_unique on fa_depreciation_runs (tenant_id, company_id, period) where status = 'posted'");
        Rls::enable('fa_depreciation_runs');

        Schema::create('fa_depreciation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('run_id')->constrained('fa_depreciation_runs')->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('fa_categories')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->bigInteger('amount_minor');
            $table->decimal('units', 20, 6)->nullable();
            $table->date('previous_depreciated_to')->nullable();
            $table->bigInteger('accumulated_after_minor');
            $table->bigInteger('book_value_after_minor');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'run_id', 'asset_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        $check('fa_depreciation_lines', 'amount', 'amount_minor > 0 and to_date >= from_date');
        Rls::enable('fa_depreciation_lines');

        // FA-14: transfers between places, custodians and cost centres.
        Schema::create('fa_transfers', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->foreignUuid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('to_location_id')->constrained('locations')->restrictOnDelete();
            $userRef($table, 'from_custodian_user_id');
            $table->uuid('from_custodian_employee_id')->nullable();
            $userRef($table, 'to_custodian_user_id');
            $table->uuid('to_custodian_employee_id')->nullable();
            $table->foreignUuid('from_cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->foreignUuid('to_cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->date('transfer_date');
            $table->text('reason');
            $table->string('status', 12);
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
        $check('fa_transfers', 'status', "status in ('pending', 'applied', 'rejected', 'cancelled')");
        DB::statement("create unique index fa_transfers_pending_unique on fa_transfers (tenant_id, asset_id) where status = 'pending'");
        Rls::enable('fa_transfers');

        // FA-17: disposals and what they posted.
        Schema::create('fa_disposals', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->string('kind', 12);
            $table->date('disposal_date');
            $currency($table, 'currency');
            $table->bigInteger('proceeds_minor')->default(0);
            $table->fxSnapshot('fx');
            $table->bigInteger('base_proceeds_minor')->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 12);
            $table->bigInteger('depreciation_minor')->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->bigInteger('accumulated_minor')->nullable();
            $table->bigInteger('impairment_minor')->nullable();
            $table->bigInteger('book_value_minor')->nullable();
            $table->bigInteger('gain_minor')->nullable();
            $table->bigInteger('surplus_minor')->nullable();
            $table->string('post_error', 60)->nullable();
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'posted_by');
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
        $check('fa_disposals', 'status', "status in ('pending', 'approved', 'posted', 'rejected', 'cancelled') and kind in ('sale', 'scrap', 'write_off') and proceeds_minor >= 0");
        DB::statement("create unique index fa_disposals_open_unique on fa_disposals (tenant_id, asset_id) where status in ('pending', 'approved', 'posted')");
        Rls::enable('fa_disposals');

        // FA-15, FA-16: revaluations and impairments.
        Schema::create('fa_revaluations', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->string('kind', 12);
            $table->date('revalued_on');
            $table->bigInteger('value_minor');
            $table->jsonb('before');
            $table->jsonb('after');
            $table->bigInteger('surplus_change_minor');
            $table->bigInteger('pnl_change_minor');
            $table->text('reason');
            $userRef($table, 'posted_by', nullable: false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'company_id', 'revalued_on']);
        });
        $check('fa_revaluations', 'kind', "kind in ('revaluation', 'impairment') and value_minor >= 0");
        Rls::enable('fa_revaluations');

        // FA-18: meter readings.
        Schema::create('fa_readings', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->date('read_on');
            $table->decimal('reading', 20, 6);
            $table->string('note', 500)->nullable();
            $userRef($table, 'recorded_by', nullable: false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id', 'read_on']);
        });
        $check('fa_readings', 'reading', 'reading >= 0');
        Rls::enable('fa_readings');

        // FA-19: maintenance schedules and work orders.
        Schema::create('fa_maintenance_schedules', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('basis', 10);
            $table->integer('interval_days')->nullable();
            $table->decimal('interval_units', 20, 6)->nullable();
            $table->integer('warning_days')->default(7);
            $table->date('last_done_on')->nullable();
            $table->decimal('last_done_reading', 20, 6)->nullable();
            $table->date('next_due_on')->nullable();
            $table->decimal('next_due_reading', 20, 6)->nullable();
            $table->string('reminded_for', 40)->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'next_due_on']);
        });
        $check('fa_maintenance_schedules', 'basis', "basis in ('time', 'usage') and warning_days between 0 and 365
            and (basis <> 'time' or interval_days between 1 and 3650) and (basis <> 'usage' or interval_units > 0)");
        Rls::enable('fa_maintenance_schedules');

        Schema::create('fa_work_orders', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('asset_id')->constrained('fa_assets')->restrictOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('fa_maintenance_schedules')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 12);
            $table->date('due_on')->nullable();
            $userRef($table, 'assigned_to');
            $table->foreignUuid('vendor_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->date('completed_on')->nullable();
            $table->decimal('reading', 20, 6)->nullable();
            $currency($table, 'currency', nullable: true);
            $table->bigInteger('cost_minor')->nullable();
            $table->fxSnapshot('fx');
            $table->bigInteger('base_cost_minor')->nullable();
            $userRef($table, 'created_by', nullable: false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'company_id', 'status']);
        });
        $check('fa_work_orders', 'status', "status in ('open', 'in_progress', 'done', 'cancelled') and (cost_minor is null or cost_minor >= 0)");
        Rls::enable('fa_work_orders');

        // FA-22: what was raised to Accounting, one row per source document.
        Schema::create('fa_postings', function (Blueprint $table) use ($currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 60);
            $table->string('document_type', 20);
            $table->uuid('document_id');
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->date('posted_on');
            $currency($table, 'currency');
            $table->jsonb('amounts');
            $table->string('reference', 120)->nullable();
            $table->boolean('reverse')->default(false);
            $table->uuid('reverses_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'posted_on']);
            $table->index(['tenant_id', 'document_type', 'document_id']);
        });
        Rls::enable('fa_postings');
    }

    public function down(): void
    {
        foreach (['fa_postings', 'fa_work_orders', 'fa_maintenance_schedules', 'fa_readings', 'fa_revaluations', 'fa_disposals', 'fa_transfers',
            'fa_depreciation_lines', 'fa_depreciation_runs', 'fa_asset_events', 'fa_asset_documents', 'fa_assets', 'fa_item_categories', 'fa_categories', 'fa_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
