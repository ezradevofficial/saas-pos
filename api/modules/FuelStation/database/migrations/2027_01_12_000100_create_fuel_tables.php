<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fuel station module (docs/modules/fuel-station.md, FUEL-01 to FUEL-38). Every
// table is the tenant's, under forced row-level security (TEN-01). Money is
// bigint minor units with its currency (ADR 003); a price is minor units per
// litre. Litres are numeric(14,3) (meters numeric(16,3)). POS sales, payments,
// items, parties and locations are named by id only: this module never
// references POS tables (CLAUDE.md, modular monolith). Users are referenced by
// (tenant_id, id).
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };
        $place = function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
        };

        // FUEL-03: one row of settings per company; every tolerance defaults to 0.
        Schema::create('fuel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('cash_variance_tolerance_minor')->default(0);
            $table->decimal('litre_tolerance', 14, 3)->default(0);
            $table->decimal('loss_tolerance_percent', 7, 4)->default(0);
            $table->decimal('loss_tolerance_litres', 14, 3)->default(0);
            $table->decimal('delivery_tolerance_percent', 7, 4)->default(0);
            $table->timestampsTz();
        });
        DB::statement('alter table fuel_settings add constraint fuel_settings_values_check check (cash_variance_tolerance_minor >= 0 and litre_tolerance >= 0
            and loss_tolerance_percent between 0 and 100 and loss_tolerance_litres >= 0 and delivery_tolerance_percent between 0 and 100)');
        Rls::enable('fuel_settings');

        // FUEL-02: a location marked as a forecourt.
        Schema::create('fuel_stations', function (Blueprint $table) use ($place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->char('currency', 3);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        Rls::enable('fuel_stations');

        // FUEL-04
        Schema::create('fuel_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->unique()->constrained('items')->restrictOnDelete();
            $table->string('fuel_type', 10);
            $table->string('grade', 60)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
        });
        DB::statement("alter table fuel_products add constraint fuel_products_type_check check (fuel_type in ('petrol', 'diesel', 'kerosene', 'lpg', 'other'))");
        Rls::enable('fuel_products');

        // FUEL-05
        Schema::create('fuel_tanks', function (Blueprint $table) use ($place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('fuel_products')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->decimal('capacity_litres', 14, 3);
            $table->decimal('tolerance_litres', 14, 3)->nullable();
            // FUEL-24: the physical stock at the last reconciled dip; book stock follows from it.
            $table->decimal('baseline_litres', 14, 3)->nullable();
            $table->timestampTz('baseline_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('station_id');
            $table->index('product_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('alter table fuel_tanks add constraint fuel_tanks_values_check check (capacity_litres > 0 and (tolerance_litres is null or tolerance_litres >= 0)
            and (baseline_litres is null or baseline_litres >= 0))');
        DB::statement('create unique index fuel_tanks_code_unique on fuel_tanks (station_id, lower(code))');
        Rls::enable('fuel_tanks');

        // FUEL-06: calibration charts are immutable; a new chart is a new row.
        Schema::create('fuel_strapping_charts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('tank_id')->constrained('fuel_tanks')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tank_id', 'effective_from']);
            $table->index('company_id');
        });
        Rls::enable('fuel_strapping_charts');

        Schema::create('fuel_strapping_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('chart_id')->constrained('fuel_strapping_charts')->restrictOnDelete();
            $table->decimal('dip_cm', 8, 2);
            $table->decimal('litres', 14, 3);

            $table->unique(['chart_id', 'dip_cm']);
        });
        DB::statement('alter table fuel_strapping_points add constraint fuel_strapping_points_values_check check (dip_cm >= 0 and litres >= 0)');
        Rls::enable('fuel_strapping_points');

        // FUEL-07
        Schema::create('fuel_pumps', function (Blueprint $table) use ($place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('station_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('create unique index fuel_pumps_code_unique on fuel_pumps (station_id, lower(code))');
        Rls::enable('fuel_pumps');

        Schema::create('fuel_nozzles', function (Blueprint $table) use ($place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->foreignUuid('pump_id')->constrained('fuel_pumps')->restrictOnDelete();
            $table->foreignUuid('tank_id')->constrained('fuel_tanks')->restrictOnDelete();
            $table->string('code', 20);
            // FUEL-16: the last accepted meter reading; null until the first shift opens it.
            $table->decimal('last_reading_litres', 16, 3)->nullable();
            $table->timestampTz('last_reading_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('station_id');
            $table->index('pump_id');
            $table->index('tank_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('alter table fuel_nozzles add constraint fuel_nozzles_values_check check (last_reading_litres is null or last_reading_litres >= 0)');
        DB::statement('create unique index fuel_nozzles_code_unique on fuel_nozzles (pump_id, lower(code))');
        Rls::enable('fuel_nozzles');

        // FUEL-08: immutable, effective-dated, price per litre in minor units.
        Schema::create('fuel_prices', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('fuel_products')->restrictOnDelete();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->timestampTz('effective_from');
            $table->string('reference', 200)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $userRef($table, 'entered_by');
            $table->timestampsTz();

            $table->index(['station_id', 'product_id', 'effective_from']);
            $table->index('product_id');
            $table->index('company_id');
        });
        DB::statement('alter table fuel_prices add constraint fuel_prices_values_check check (price_minor > 0)');
        DB::statement('create unique index fuel_prices_start_unique on fuel_prices (station_id, product_id, effective_from) where archived_at is null');
        Rls::enable('fuel_prices');

        // FUEL-09, FUEL-10: a shift, uploaded by the till (the id is the device's).
        Schema::create('fuel_shifts', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->string('status', 8)->default('open');
            $userRef($table, 'opened_by');
            $table->timestampTz('opened_at');
            $table->timestampTz('opened_received_at');
            $userRef($table, 'closed_by');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('closed_received_at')->nullable();
            $table->char('opening_fingerprint', 64);
            $table->char('closing_fingerprint', 64)->nullable();
            $table->jsonb('flags')->default('[]');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['station_id', 'status']);
            $table->index('device_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table fuel_shifts add constraint fuel_shifts_values_check check (status in ('open', 'closed') and ((status = 'closed') = (closed_at is not null)))");
        Rls::enable('fuel_shifts');

        Schema::create('fuel_shift_nozzles', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('shift_id')->constrained('fuel_shifts')->restrictOnDelete();
            $table->foreignUuid('nozzle_id')->constrained('fuel_nozzles')->restrictOnDelete();
            $userRef($table, 'attendant_id');
            $table->boolean('is_open')->default(true);
            $table->decimal('opening_litres', 16, 3);
            $table->decimal('closing_litres', 16, 3)->nullable();
            $table->decimal('test_litres', 14, 3)->default(0);
            $table->decimal('reset_litres', 16, 3)->default(0);
            $table->decimal('gap_litres', 16, 3)->default(0);
            $table->decimal('meter_litres', 16, 3)->nullable();
            $table->decimal('recorded_litres', 16, 3)->default(0);
            $table->bigInteger('recorded_minor')->default(0);
            $table->decimal('unrecorded_litres', 16, 3)->default(0);
            $table->bigInteger('price_minor')->nullable();
            $table->bigInteger('expected_minor')->default(0);
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->unique(['shift_id', 'nozzle_id']);
            $table->index('nozzle_id');
            $table->index('attendant_id');
        });
        DB::statement('alter table fuel_shift_nozzles add constraint fuel_shift_nozzles_values_check check (opening_litres >= 0 and test_litres >= 0 and (closing_litres is null or closing_litres >= 0))');
        // FUEL-09: a nozzle is on at most one open shift.
        DB::statement('create unique index fuel_shift_nozzles_one_open on fuel_shift_nozzles (nozzle_id) where is_open');
        Rls::enable('fuel_shift_nozzles');

        // FUEL-13: one settlement per attendant and shift.
        Schema::create('fuel_settlements', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('shift_id')->constrained('fuel_shifts')->restrictOnDelete();
            $userRef($table, 'attendant_id');
            $table->string('number', 40)->nullable();
            $table->string('status', 16)->default('open');
            $table->integer('version')->default(1);
            $table->char('currency', 3);
            $table->bigInteger('expected_minor')->default(0);
            $table->bigInteger('collected_minor')->default(0);
            $table->bigInteger('variance_minor')->default(0);
            $table->decimal('meter_litres', 16, 3)->default(0);
            $table->decimal('recorded_litres', 16, 3)->default(0);
            $table->decimal('litres_variance', 16, 3)->default(0);
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampsTz();

            $table->unique(['shift_id', 'attendant_id']);
            $table->index('attendant_id');
            $table->index(['location_id', 'status']);
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table fuel_settlements add constraint fuel_settlements_values_check check (status in ('open', 'pending_approval', 'auto_accepted', 'approved', 'rejected') and version >= 1)");
        DB::statement('create unique index fuel_settlements_number_unique on fuel_settlements (tenant_id, number) where number is not null');
        Rls::enable('fuel_settlements');

        Schema::create('fuel_settlement_tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('settlement_id')->constrained('fuel_settlements')->restrictOnDelete();
            $table->string('kind', 14);
            $table->bigInteger('declared_minor')->nullable();
            $table->bigInteger('recorded_minor')->default(0);
            $table->bigInteger('collected_minor')->default(0);
            $table->timestampsTz();

            $table->unique(['settlement_id', 'kind']);
        });
        DB::statement("alter table fuel_settlement_tenders add constraint fuel_settlement_tenders_kind_check check (kind in ('cash', 'mobile_money', 'card', 'credit', 'voucher') and (declared_minor is null or declared_minor >= 0))");
        Rls::enable('fuel_settlement_tenders');

        // FUEL-17
        Schema::create('fuel_meter_resets', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('nozzle_id')->constrained('fuel_nozzles')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('fuel_shifts')->restrictOnDelete();
            $table->decimal('reading_before', 16, 3);
            $table->decimal('reading_after', 16, 3);
            $table->text('reason');
            $table->string('status', 8)->default('pending');
            $userRef($table, 'requested_by');
            $table->timestampTz('requested_at');
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->boolean('actor_verified')->default(false);
            $table->timestampsTz();

            $table->index(['nozzle_id', 'status']);
            $table->index('shift_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table fuel_meter_resets add constraint fuel_meter_resets_values_check check (status in ('pending', 'approved', 'rejected') and reading_before >= 0 and reading_after >= 0)");
        Rls::enable('fuel_meter_resets');

        // FUEL-23 to FUEL-26: dips, then the reconciliations they trigger.
        Schema::create('fuel_dips', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('tank_id')->constrained('fuel_tanks')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('fuel_shifts')->restrictOnDelete();
            $table->foreignUuid('chart_id')->constrained('fuel_strapping_charts')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('source', 8);
            $table->decimal('dip_cm', 8, 2);
            $table->decimal('water_cm', 8, 2)->nullable();
            $table->decimal('litres', 14, 3);
            $table->timestampTz('taken_at');
            $table->timestampTz('received_at');
            $userRef($table, 'taken_by');
            $table->timestampsTz();

            $table->index(['tank_id', 'taken_at']);
            $table->index('shift_id');
            $table->index('chart_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table fuel_dips add constraint fuel_dips_values_check check (kind in ('routine', 'shift_open', 'shift_close', 'delivery_before', 'delivery_after')
            and source in ('device', 'web') and dip_cm >= 0 and litres >= 0)");
        Rls::enable('fuel_dips');

        Schema::create('fuel_reconciliations', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('tank_id')->constrained('fuel_tanks')->restrictOnDelete();
            $table->foreignUuid('dip_id')->unique()->constrained('fuel_dips')->restrictOnDelete();
            $table->string('number', 40);
            $table->string('status', 16);
            $table->decimal('previous_litres', 14, 3)->nullable();
            $table->timestampTz('baseline_at')->nullable();
            $table->decimal('deliveries_litres', 14, 3)->default(0);
            $table->decimal('sales_litres', 14, 3)->default(0);
            $table->decimal('expected_litres', 14, 3)->default(0);
            $table->decimal('dip_litres', 14, 3);
            $table->decimal('variance_litres', 14, 3)->default(0);
            $table->decimal('tolerance_litres', 14, 3)->default(0);
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->index(['tank_id', 'created_at']);
            $table->index(['location_id', 'status']);
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table fuel_reconciliations add constraint fuel_reconciliations_values_check check (status in ('baseline', 'accepted', 'pending_approval', 'approved', 'rejected'))");
        DB::statement('create unique index fuel_reconciliations_number_unique on fuel_reconciliations (tenant_id, number)');
        Rls::enable('fuel_reconciliations');

        // FUEL-25
        Schema::create('fuel_deliveries', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('tank_id')->constrained('fuel_tanks')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('before_dip_id')->constrained('fuel_dips')->restrictOnDelete();
            $table->foreignUuid('after_dip_id')->constrained('fuel_dips')->restrictOnDelete();
            $table->foreignUuid('reconciliation_id')->nullable()->constrained('fuel_reconciliations')->restrictOnDelete();
            $table->string('number', 40);
            $table->string('note_number', 60);
            $table->decimal('note_litres', 14, 3);
            $table->decimal('before_litres', 14, 3);
            $table->decimal('after_litres', 14, 3);
            $table->decimal('sold_during_litres', 14, 3)->default(0);
            $table->decimal('received_litres', 14, 3);
            $table->decimal('shortage_litres', 14, 3);
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('cost_minor');
            $table->char('currency', 3);
            $table->timestampTz('received_at');
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'recorded_by');
            $table->timestampsTz();

            $table->index(['tank_id', 'received_at']);
            $table->index('supplier_id');
            $table->index('before_dip_id');
            $table->index('after_dip_id');
            $table->index('reconciliation_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('alter table fuel_deliveries add constraint fuel_deliveries_values_check check (note_litres > 0 and received_litres > 0 and sold_during_litres >= 0 and unit_cost_minor >= 0 and cost_minor >= 0)');
        DB::statement('create unique index fuel_deliveries_number_unique on fuel_deliveries (tenant_id, number)');
        DB::statement('create unique index fuel_deliveries_note_unique on fuel_deliveries (tank_id, lower(note_number))');
        Rls::enable('fuel_deliveries');

        // FUEL-20
        Schema::create('fuel_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('registration', 20);
            $table->string('label', 120)->nullable();
            $table->string('fuel_type', 10)->nullable();
            $table->decimal('daily_litre_limit', 14, 3)->nullable();
            $table->string('status', 8)->default('active');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'registration']);
            $table->index('party_id');
        });
        DB::statement("alter table fuel_vehicles add constraint fuel_vehicles_values_check check (status in ('active', 'blocked') and (daily_litre_limit is null or daily_litre_limit > 0)
            and (fuel_type is null or fuel_type in ('petrol', 'diesel', 'kerosene', 'lpg', 'other')))");
        Rls::enable('fuel_vehicles');

        // FUEL-21
        Schema::create('fuel_vouchers', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('code', 40);
            $table->char('currency', 3);
            $table->bigInteger('value_minor');
            $table->bigInteger('balance_minor');
            $table->string('fuel_type', 10)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 8)->default('active');
            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $userRef($table, 'issued_by');
            $table->timestampsTz();

            $table->index('party_id');
            $table->index('company_id');
        });
        DB::statement("alter table fuel_vouchers add constraint fuel_vouchers_values_check check (status in ('active', 'void') and value_minor > 0 and balance_minor >= 0
            and (fuel_type is null or fuel_type in ('petrol', 'diesel', 'kerosene', 'lpg', 'other')))");
        DB::statement('create unique index fuel_vouchers_code_unique on fuel_vouchers (company_id, upper(code))');
        Rls::enable('fuel_vouchers');

        Schema::create('fuel_voucher_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('voucher_id')->constrained('fuel_vouchers')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->uuid('sale_id');
            $table->uuid('payment_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('redeemed_at');
            $table->timestampTz('reversed_at')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->unique(['sale_id', 'payment_id']);
            $table->index('voucher_id');
            $table->index('location_id');
        });
        DB::statement('alter table fuel_voucher_redemptions add constraint fuel_voucher_redemptions_values_check check (amount_minor > 0)');
        Rls::enable('fuel_voucher_redemptions');

        // FUEL-18, FUEL-12: one row per fuel sale line, and the fuel share of each sale's payments by tender kind.
        Schema::create('fuel_sales', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('station_id')->constrained('fuel_stations')->restrictOnDelete();
            $table->uuid('sale_id');
            $table->uuid('sale_line_id');
            $table->foreignUuid('shift_id')->nullable()->constrained('fuel_shifts')->restrictOnDelete();
            $table->uuid('claimed_shift_id')->nullable();
            $table->foreignUuid('shift_nozzle_id')->nullable()->constrained('fuel_shift_nozzles')->restrictOnDelete();
            $table->foreignUuid('nozzle_id')->nullable()->constrained('fuel_nozzles')->restrictOnDelete();
            $table->foreignUuid('tank_id')->nullable()->constrained('fuel_tanks')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('fuel_products')->restrictOnDelete();
            $table->foreignUuid('reconciliation_id')->nullable()->constrained('fuel_reconciliations')->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('fuel_vehicles')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $userRef($table, 'attendant_id');
            $userRef($table, 'cashier_id');
            $table->string('vehicle_registration', 20)->nullable();
            $table->integer('odometer')->nullable();
            $table->decimal('litres', 14, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->timestampTz('sold_at');
            $table->timestampTz('received_at');
            $table->timestampTz('voided_at')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->unique('sale_line_id');
            $table->index('sale_id');
            $table->index(['shift_id', 'nozzle_id']);
            $table->index('claimed_shift_id');
            $table->index(['tank_id', 'sold_at']);
            $table->index('station_id');
            $table->index('shift_nozzle_id');
            $table->index('nozzle_id');
            $table->index('product_id');
            $table->index('reconciliation_id');
            $table->index('vehicle_id');
            $table->index('customer_id');
            $table->index('attendant_id');
            $table->index('cashier_id');
            $table->index('location_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('alter table fuel_sales add constraint fuel_sales_values_check check (litres > 0 and unit_price_minor >= 0 and total_minor >= 0)');
        Rls::enable('fuel_sales');

        Schema::create('fuel_sale_tenders', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('sale_id');
            $table->uuid('refund_id')->nullable();
            $table->foreignUuid('shift_id')->nullable()->constrained('fuel_shifts')->restrictOnDelete();
            $userRef($table, 'attendant_id');
            $table->string('kind', 14);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->index('sale_id');
            $table->index(['shift_id', 'attendant_id']);
            $table->index('attendant_id');
        });
        DB::statement("alter table fuel_sale_tenders add constraint fuel_sale_tenders_kind_check check (kind in ('cash', 'mobile_money', 'card', 'credit', 'voucher'))");
        DB::statement('create unique index fuel_sale_tenders_sale_once on fuel_sale_tenders (sale_id, kind) where refund_id is null');
        DB::statement('create unique index fuel_sale_tenders_refund_once on fuel_sale_tenders (refund_id, kind) where refund_id is not null');
        Rls::enable('fuel_sale_tenders');
    }

    public function down(): void
    {
        foreach ([
            'fuel_sale_tenders', 'fuel_sales', 'fuel_voucher_redemptions', 'fuel_vouchers', 'fuel_vehicles', 'fuel_deliveries', 'fuel_reconciliations', 'fuel_dips',
            'fuel_meter_resets', 'fuel_settlement_tenders', 'fuel_settlements', 'fuel_shift_nozzles', 'fuel_shifts', 'fuel_prices', 'fuel_nozzles', 'fuel_pumps',
            'fuel_strapping_points', 'fuel_strapping_charts', 'fuel_tanks', 'fuel_products', 'fuel_stations', 'fuel_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
