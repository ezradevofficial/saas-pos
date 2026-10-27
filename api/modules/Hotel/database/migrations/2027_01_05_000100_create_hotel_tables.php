<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Hotel and lodging module (docs/modules/hotel.md, HOT-01 to HOT-38). Every
// table is the tenant's, under forced row-level security (TEN-01). Money is
// bigint minor units with its currency (ADR 003). Folio lines are an
// append-only ledger (a trigger refuses update and delete, also for the
// owner). POS sales are named by id only: this module never references POS
// tables (CLAUDE.md, modular monolith). Users are referenced by (tenant_id, id).
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        // HOT-02: one row of settings per company.
        Schema::create('hotel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('check_in_time', 5)->default('14:00');
            $table->string('check_out_time', 5)->default('11:00');
            $table->string('night_audit_time', 5)->default('03:00');
            $table->bigInteger('early_checkin_fee_minor')->default(0);
            $table->bigInteger('late_checkout_fee_minor')->default(0);
            $table->char('fee_currency', 3)->nullable();
            $table->jsonb('weekend_days')->default('[5,6]');
            $table->integer('cancel_free_days')->default(1);
            $table->string('cancel_charge_kind', 8)->default('nights');
            $table->bigInteger('cancel_charge_value')->default(1);
            $table->string('noshow_charge_kind', 8)->default('nights');
            $table->bigInteger('noshow_charge_value')->default(1);
            $table->integer('deposit_percent')->default(0);
            $table->boolean('require_guest_id')->default(true);
            $table->foreignUuid('room_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->jsonb('levy_tax_code_ids')->default('[]');
            $table->bigInteger('charge_limit_minor')->nullable();
            $table->foreignUuid('room_charge_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('room_item_id');
            $table->index('room_charge_method_id');
        });

        DB::statement("alter table hotel_settings add constraint hotel_settings_values_check check (
            cancel_charge_kind in ('none', 'nights', 'percent', 'fixed') and noshow_charge_kind in ('none', 'nights', 'percent', 'fixed')
            and cancel_free_days between 0 and 365 and cancel_charge_value >= 0 and noshow_charge_value >= 0
            and deposit_percent between 0 and 100 and early_checkin_fee_minor >= 0 and late_checkout_fee_minor >= 0
            and (charge_limit_minor is null or charge_limit_minor >= 0)
            and (cancel_charge_kind <> 'percent' or cancel_charge_value <= 100) and (noshow_charge_kind <> 'percent' or noshow_charge_value <= 100))");
        Rls::enable('hotel_settings');

        // HOT-30: a property is a branch; its business date rolls with the night audit.
        Schema::create('hotel_properties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->unique()->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->timestampTz('rolled_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
        });
        Rls::enable('hotel_properties');

        // HOT-03
        Schema::create('hotel_room_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->smallInteger('base_adults')->default(2);
            $table->smallInteger('max_adults')->default(2);
            $table->smallInteger('max_children')->default(0);
            $table->smallInteger('max_occupancy')->default(2);
            $table->string('beds', 100)->nullable();
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
        });

        DB::statement('alter table hotel_room_types add constraint hotel_room_types_values_check check (base_adults between 1 and 20 and max_adults between base_adults and 20
            and max_children between 0 and 20 and max_occupancy between 1 and 40 and max_occupancy >= base_adults)');
        DB::statement('create unique index hotel_room_types_code_unique on hotel_room_types (company_id, lower(code))');
        Rls::enable('hotel_room_types');

        // HOT-04, HOT-05
        Schema::create('hotel_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('room_type_id')->constrained('hotel_room_types')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('floor', 20)->nullable();
            $table->jsonb('features')->default('[]');
            $table->text('notes')->nullable();
            $table->string('status', 14)->default('vacant_clean');
            $table->text('ooo_reason')->nullable();
            $table->date('ooo_from')->nullable();
            $table->date('ooo_until')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
            $table->index('room_type_id');
            $table->index('company_id');
        });

        DB::statement("alter table hotel_rooms add constraint hotel_rooms_status_check check (status in ('vacant_clean', 'vacant_dirty', 'occupied', 'out_of_order')
            and (ooo_from is null or ooo_until is null or ooo_from <= ooo_until))");
        DB::statement('create unique index hotel_rooms_number_unique on hotel_rooms (branch_id, lower(number))');
        Rls::enable('hotel_rooms');

        // HOT-06 to HOT-09
        Schema::create('hotel_rate_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('room_type_id')->constrained('hotel_room_types')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('board', 14)->default('room_only');
            $table->char('currency', 3);
            $table->bigInteger('weekday_rate_minor');
            $table->bigInteger('weekend_rate_minor');
            $table->bigInteger('extra_adult_minor')->default(0);
            $table->bigInteger('child_rate_minor')->default(0);
            $table->smallInteger('free_children')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->integer('cancel_free_days')->nullable();
            $table->string('cancel_charge_kind', 8)->nullable();
            $table->bigInteger('cancel_charge_value')->nullable();
            $table->string('noshow_charge_kind', 8)->nullable();
            $table->bigInteger('noshow_charge_value')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('room_type_id');
            $table->index('company_id');
        });

        DB::statement("alter table hotel_rate_plans add constraint hotel_rate_plans_values_check check (
            board in ('room_only', 'bed_breakfast', 'half_board') and weekday_rate_minor >= 0 and weekend_rate_minor >= 0 and extra_adult_minor >= 0 and child_rate_minor >= 0
            and free_children >= 0 and (valid_from is null or valid_until is null or valid_from <= valid_until)
            and (cancel_charge_kind is null or cancel_charge_kind in ('none', 'nights', 'percent', 'fixed'))
            and (noshow_charge_kind is null or noshow_charge_kind in ('none', 'nights', 'percent', 'fixed')))");
        DB::statement('create unique index hotel_rate_plans_code_unique on hotel_rate_plans (company_id, lower(code))');
        Rls::enable('hotel_rate_plans');

        Schema::create('hotel_rate_seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('rate_plan_id')->constrained('hotel_rate_plans')->restrictOnDelete();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('weekday_rate_minor');
            $table->bigInteger('weekend_rate_minor')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->timestampsTz();

            $table->index('rate_plan_id');
        });

        DB::statement('alter table hotel_rate_seasons add constraint hotel_rate_seasons_values_check check (starts_on <= ends_on and weekday_rate_minor >= 0 and (weekend_rate_minor is null or weekend_rate_minor >= 0))');
        Rls::enable('hotel_rate_seasons');

        // HOT-16: a guest is a customer party with identity document fields (personal data, NFR-08).
        Schema::create('hotel_guests', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->unique()->constrained('parties')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('id_type', 16)->nullable();
            $table->text('id_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index('company_id');
        });

        DB::statement("alter table hotel_guests add constraint hotel_guests_id_type_check check (id_type is null or id_type in ('national_id', 'passport', 'driving_licence', 'other'))");
        Rls::enable('hotel_guests');

        // HOT-12
        Schema::create('hotel_reservations', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('kind', 8)->default('direct');
            $table->foreignUuid('guest_id')->constrained('hotel_guests')->restrictOnDelete();
            $table->string('group_name', 120)->nullable();
            $table->string('source', 12)->default('direct');
            $table->text('notes')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index('branch_id');
            $table->index('guest_id');
            $table->index('company_id');
        });

        DB::statement("alter table hotel_reservations add constraint hotel_reservations_values_check check (kind in ('direct', 'walk_in', 'group') and source in ('direct', 'phone', 'walk_in', 'online', 'agent', 'other'))");
        DB::statement('create unique index hotel_reservations_number_unique on hotel_reservations (tenant_id, number)');
        Rls::enable('hotel_reservations');

        Schema::create('hotel_stays', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained('hotel_reservations')->restrictOnDelete();
            $table->foreignUuid('guest_id')->constrained('hotel_guests')->restrictOnDelete();
            $table->foreignUuid('room_type_id')->constrained('hotel_room_types')->restrictOnDelete();
            $table->foreignUuid('rate_plan_id')->constrained('hotel_rate_plans')->restrictOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained('hotel_rooms')->restrictOnDelete();
            $table->date('arrival_on');
            $table->date('departure_on');
            $table->smallInteger('adults')->default(1);
            $table->smallInteger('children')->default(0);
            $table->string('board', 14);
            $table->char('currency', 3);
            $table->jsonb('nightly')->default('[]');
            $table->bigInteger('total_minor')->default(0);
            $table->boolean('rate_overridden')->default(false);
            $table->string('status', 12)->default('reserved');
            $table->boolean('bill_to_master')->default(false);
            $table->smallInteger('keys_issued')->default(0);
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->text('notes')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['branch_id', 'room_id', 'status']);
            $table->index(['room_type_id', 'status', 'arrival_on']);
            $table->index('reservation_id');
            $table->index('guest_id');
            $table->index('rate_plan_id');
            $table->index('room_id');
            $table->index('company_id');
        });

        DB::statement("alter table hotel_stays add constraint hotel_stays_values_check check (
            status in ('reserved', 'checked_in', 'checked_out', 'cancelled', 'no_show') and arrival_on < departure_on and adults >= 1 and children >= 0 and keys_issued >= 0
            and board in ('room_only', 'bed_breakfast', 'half_board'))");
        Rls::enable('hotel_stays');

        // HOT-20
        Schema::create('hotel_folios', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('reservation_id')->constrained('hotel_reservations')->restrictOnDelete();
            $table->foreignUuid('stay_id')->nullable()->constrained('hotel_stays')->restrictOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained('hotel_guests')->restrictOnDelete();
            $table->string('kind', 8)->default('guest');
            $table->string('number', 40);
            $table->char('currency', 3);
            $table->string('status', 8)->default('open');
            $table->timestampTz('settled_at')->nullable();
            $userRef($table, 'settled_by');
            $table->string('invoice_number', 40)->nullable();
            $table->bigInteger('balance_left_minor')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('reservation_id');
            $table->index('stay_id');
            $table->index('guest_id');
            $table->index(['branch_id', 'status']);
            $table->index('company_id');
        });

        DB::statement("alter table hotel_folios add constraint hotel_folios_values_check check (kind in ('guest', 'master') and status in ('open', 'settled')
            and ((kind = 'guest') = (stay_id is not null)) and ((status = 'settled') = (settled_at is not null)))");
        DB::statement('create unique index hotel_folios_number_unique on hotel_folios (tenant_id, number)');
        DB::statement("create unique index hotel_folios_one_per_stay on hotel_folios (stay_id) where kind = 'guest'");
        DB::statement("create unique index hotel_folios_one_master on hotel_folios (reservation_id) where kind = 'master'");
        Rls::enable('hotel_folios');

        // HOT-20, HOT-21: the append-only ledger. Charges are positive, payments negative.
        Schema::create('hotel_folio_lines', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('folio_id')->constrained('hotel_folios')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('kind', 12);
            $table->string('description', 240);
            $table->foreignUuid('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 12, 3)->default(1);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->jsonb('taxes')->default('[]');
            $table->char('currency', 3);
            // CUR-04: the rate to the company's base currency when the folio is in another one.
            $table->fxSnapshot('fx');
            $table->bigInteger('base_amount_minor')->nullable();
            $table->date('business_date');
            $table->string('source', 24);
            $table->string('source_id', 80)->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->uuid('pair_id')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->uuid('payment_id')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['folio_id', 'line_no']);
            $table->index(['business_date', 'kind']);
            $table->index('item_id');
            $table->index('payment_method_id');
            $table->index('reverses_id');
            $table->index('pair_id');
        });

        DB::statement("alter table hotel_folio_lines add constraint hotel_folio_lines_values_check check (
            kind in ('room', 'extra', 'fee', 'pos_charge', 'deposit', 'payment', 'refund', 'transfer_in', 'transfer_out', 'void') and amount_minor <> 0)");
        DB::statement('create unique index hotel_folio_lines_once on hotel_folio_lines (folio_id, source, source_id) where source_id is not null');
        DB::statement('create unique index hotel_folio_lines_number on hotel_folio_lines (folio_id, line_no)');
        DB::statement('create unique index hotel_folio_lines_reversed_once on hotel_folio_lines (reverses_id) where reverses_id is not null');
        Rls::enable('hotel_folio_lines');

        // HOT-14, HOT-24: a payment attempt and where it stands (M-Pesa may take a minute).
        Schema::create('hotel_payments', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('folio_id')->constrained('hotel_folios')->restrictOnDelete();
            $table->string('kind', 8);
            $table->string('status', 8)->default('pending');
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('method_type', 20);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('reference', 120)->nullable();
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('line_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->text('failure')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['folio_id', 'status']);
            $table->index('payment_method_id');
            $table->index('payment_intent_id');
        });

        DB::statement("alter table hotel_payments add constraint hotel_payments_values_check check (kind in ('deposit', 'payment', 'refund') and status in ('pending', 'settled', 'failed') and amount_minor > 0)");
        Rls::enable('hotel_payments');

        // HOT-22: a room charge from a till that could not post.
        Schema::create('hotel_charge_exceptions', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('source', 24);
            $table->string('source_id', 80);
            $table->string('reference', 60)->nullable();
            $table->string('room_number', 20)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('reason', 40);
            $table->string('status', 8)->default('open');
            $table->foreignUuid('resolved_folio_id')->nullable()->constrained('hotel_folios')->restrictOnDelete();
            $table->string('resolution', 12)->nullable();
            $table->text('note')->nullable();
            $userRef($table, 'resolved_by');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'source_id']);
            $table->index(['branch_id', 'status']);
            $table->index('company_id');
            $table->index('resolved_folio_id');
        });

        DB::statement("alter table hotel_charge_exceptions add constraint hotel_charge_exceptions_values_check check (status in ('open', 'resolved') and ((status = 'resolved') = (resolved_at is not null)))");
        Rls::enable('hotel_charge_exceptions');

        // HOT-28
        Schema::create('hotel_housekeeping_tasks', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('room_id')->constrained('hotel_rooms')->restrictOnDelete();
            $table->string('kind', 14);
            $table->string('status', 12)->default('pending');
            $table->date('business_date');
            $userRef($table, 'assigned_to');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $userRef($table, 'inspected_by');
            $table->text('notes')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['branch_id', 'business_date', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index('room_id');
            $table->index('company_id');
        });

        DB::statement("alter table hotel_housekeeping_tasks add constraint hotel_housekeeping_tasks_values_check check (
            kind in ('checkout_clean', 'stayover', 'inspection', 'deep_clean', 'maintenance') and status in ('pending', 'in_progress', 'done', 'inspected', 'cancelled'))");
        DB::statement("create unique index hotel_housekeeping_tasks_once on hotel_housekeeping_tasks (room_id, business_date, kind) where status <> 'cancelled'");
        Rls::enable('hotel_housekeeping_tasks');

        // HOT-30: one audit per branch and business date.
        Schema::create('hotel_night_audits', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 10)->default('completed');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $userRef($table, 'run_by');
            $table->jsonb('summary')->default('{}');
            $table->timestampsTz();

            $table->unique(['branch_id', 'business_date']);
            $table->index('company_id');
        });
        Rls::enable('hotel_night_audits');

        DB::unprepared(<<<'SQL'
            create or replace function hotel_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
            end;
            $$
            SQL);
        DB::statement('create trigger hotel_folio_lines_append_only before update or delete on hotel_folio_lines for each row execute function hotel_append_only()');
    }

    public function down(): void
    {
        foreach ([
            'hotel_night_audits', 'hotel_housekeeping_tasks', 'hotel_charge_exceptions', 'hotel_payments', 'hotel_folio_lines', 'hotel_folios', 'hotel_stays',
            'hotel_reservations', 'hotel_guests', 'hotel_rate_seasons', 'hotel_rate_plans', 'hotel_rooms', 'hotel_room_types', 'hotel_properties', 'hotel_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('drop function if exists hotel_append_only()');
    }
};
