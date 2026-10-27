<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Salon and services module (docs/modules/salon.md, SAL-01 to SAL-30). Every
// table is the tenant's, under forced row-level security (TEN-01). Money is
// bigint minor units with its currency (ADR 003). Business records are never
// deleted (TEN-06): services, staff, resources, rules and package types are
// archived; appointments are cancelled; ledgers are append-only. References to
// other modules' documents (a POS sale, an HR employee, a Payroll item) are
// plain uuids, never foreign keys across modules. Users are referenced by
// (tenant_id, id). Ids of appointments, lines and queue entries may be made
// on the device (UUID v7).
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

        // SAL-10, SAL-11, SAL-14, SAL-26: one row of settings per company.
        Schema::create('salon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->smallInteger('slot_minutes')->default(15);
            $table->smallInteger('cancel_window_hours')->default(24);
            $table->decimal('late_cancel_fee_percent', 5, 2)->default(0);
            $table->decimal('no_show_fee_percent', 5, 2)->default(0);
            $table->decimal('deposit_percent', 5, 2)->default(0);
            $table->boolean('deposit_online_required')->default(false);
            $table->smallInteger('hold_minutes')->default(30);
            $table->smallInteger('reminder_hours')->default(24);
            $table->smallInteger('guest_retention_days')->default(365);
            $table->foreignUuid('tip_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignUuid('fee_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->string('commission_item_code', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
            $table->index('tip_item_id');
            $table->index('fee_item_id');
        });
        DB::statement('alter table salon_settings add constraint salon_settings_check check (
            slot_minutes in (5, 10, 15, 20, 30, 60) and cancel_window_hours between 0 and 720
            and late_cancel_fee_percent between 0 and 100 and no_show_fee_percent between 0 and 100 and deposit_percent between 0 and 100
            and hold_minutes between 5 and 1440 and reminder_hours between 1 and 168 and guest_retention_days between 30 and 1825)');
        Rls::enable('salon_settings');

        // SAL-15, SAL-19: a branch's salon floor (the location whose stock the services use) and its public booking page.
        Schema::create('salon_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('online_enabled')->default(false);
            $table->string('public_code', 24);
            $table->string('public_name', 120)->nullable();
            $table->integer('lead_minutes')->default(60);
            $table->smallInteger('max_days_ahead')->default(60);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'branch_id']);
            $table->unique('public_code');
            $table->index('company_id');
            $table->index('location_id');
        });
        DB::statement('alter table salon_branches add constraint salon_branches_check check (lead_minutes between 0 and 10080 and max_days_ahead between 1 and 365)');
        Rls::enable('salon_branches');

        // SAL-02: the service menu. A service is a core `service` item with salon data.
        Schema::create('salon_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->string('kind', 8)->default('service');
            $table->smallInteger('duration_minutes');
            $table->smallInteger('buffer_minutes')->default(0);
            $table->boolean('online_bookable')->default(false);
            $table->jsonb('staff_ids')->default('[]');
            $table->jsonb('resource_ids')->default('[]');
            $table->jsonb('addon_ids')->default('[]');
            $table->jsonb('products')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('item_id');
        });
        DB::statement("alter table salon_services add constraint salon_services_check check (kind in ('service', 'addon') and duration_minutes between 5 and 600 and buffer_minutes between 0 and 120)");
        DB::statement('create unique index salon_services_name on salon_services (tenant_id, company_id, lower(name)) where archived_at is null');
        DB::statement('create unique index salon_services_item on salon_services (tenant_id, company_id, item_id) where archived_at is null');
        Rls::enable('salon_services');

        // SAL-03: staff, optionally a user and an HR employee (a plain id: no foreign key into HR).
        Schema::create('salon_staff', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $user($table, 'user_id');
            $table->uuid('employee_id')->nullable();
            $table->string('name', 120);
            $table->string('title', 80)->nullable();
            $table->string('color', 8)->default('c1');
            $table->boolean('accepts_online')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index(['tenant_id', 'employee_id']);
        });
        DB::statement('create unique index salon_staff_user on salon_staff (tenant_id, company_id, user_id) where archived_at is null and user_id is not null');
        DB::statement('create unique index salon_staff_employee on salon_staff (tenant_id, company_id, employee_id) where archived_at is null and employee_id is not null');
        Rls::enable('salon_staff');

        // SAL-04: weekly work spans and breaks per staff member and branch, in the branch's local time.
        Schema::create('salon_staff_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->smallInteger('weekday');
            $table->string('kind', 6)->default('work');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'staff_id', 'branch_id', 'weekday']);
            $table->index('branch_id');
        });
        DB::statement("alter table salon_staff_hours add constraint salon_staff_hours_check check (weekday between 1 and 7 and kind in ('work', 'break') and ends_at > starts_at)");
        Rls::enable('salon_staff_hours');

        // SAL-04: time off.
        Schema::create('salon_time_off', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 10)->default('leave');
            $table->timestampTz('cancelled_at')->nullable();
            $user($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'staff_id', 'starts_at']);
        });
        DB::statement("alter table salon_time_off add constraint salon_time_off_check check (ends_at > starts_at and reason in ('leave', 'sick', 'training', 'other'))");
        Rls::enable('salon_time_off');

        // SAL-05: chairs, basins, rooms, beds.
        Schema::create('salon_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('kind', 30)->default('chair');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('create unique index salon_resources_name on salon_resources (tenant_id, branch_id, lower(name)) where archived_at is null');
        Rls::enable('salon_resources');

        // SAL-25: the salon's profile of a customer party. Allergies and formulas are encrypted by the model (personal data, NFR-08).
        Schema::create('salon_clients', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('preferred_staff_id')->nullable()->constrained('salon_staff')->restrictOnDelete();
            $table->text('preferences')->nullable();
            $table->text('allergies')->nullable();
            $table->text('formulas')->nullable();
            $table->text('notes')->nullable();
            $user($table, 'created_by');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'party_id']);
            $table->index('company_id');
            $table->index('preferred_staff_id');
        });
        Rls::enable('salon_clients');

        // SAL-07: an appointment. Its lines hold the staff and resource times.
        Schema::create('salon_appointments', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('client_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_phone', 30)->nullable();
            $table->string('guest_email', 190)->nullable();
            $table->string('locale', 2)->default('en');
            $table->string('source', 10)->default('staff');
            $table->string('status', 12)->default('booked');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->bigInteger('total_minor')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('deposit_required_minor')->default(0);
            $table->timestampTz('deposit_hold_until')->nullable();
            $table->bigInteger('fee_minor')->default(0);
            $table->string('fee_status', 8)->default('none');
            $table->string('fee_reason', 12)->nullable();
            $table->string('fee_waive_reason', 200)->nullable();
            $user($table, 'fee_waived_by');
            $table->string('notes', 500)->nullable();
            $table->text('service_notes')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->uuid('sale_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestampTz('anonymised_at')->nullable();
            $user($table, 'created_by');
            $user($table, 'cancelled_by');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'branch_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'starts_at']);
            $table->index('company_id');
            $table->index('client_id');
            $table->index('location_id');
            $table->index('device_id');
        });
        DB::statement("alter table salon_appointments add constraint salon_appointments_check check (
            status in ('booked', 'checked_in', 'in_service', 'completed', 'paid', 'cancelled', 'no_show')
            and source in ('staff', 'walk_in', 'online', 'device') and fee_status in ('none', 'due', 'paid', 'waived')
            and ends_at > starts_at and total_minor >= 0 and fee_minor >= 0 and deposit_required_minor >= 0)");
        DB::statement('create unique index salon_appointments_sale on salon_appointments (tenant_id, sale_id) where sale_id is not null');
        DB::statement('create index salon_appointments_guest_purge on salon_appointments (tenant_id, starts_at) where anonymised_at is null and client_id is null and guest_name is not null');
        Rls::enable('salon_appointments');

        Schema::create('salon_appointment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('appointment_id')->constrained('salon_appointments')->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('salon_services')->restrictOnDelete();
            $table->uuid('item_id');
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained('salon_resources')->restrictOnDelete();
            $table->smallInteger('position')->default(1);
            $table->string('kind', 8)->default('service');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('blocked_until');
            $table->bigInteger('price_minor')->default(0);
            $table->bigInteger('net_minor')->nullable();
            $table->char('currency', 3);
            $table->boolean('holds')->default(true);
            $table->uuid('sale_line_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'appointment_id']);
            $table->index('service_id');
            $table->index('resource_id');
            $table->index(['tenant_id', 'staff_id', 'starts_at']);
            $table->index(['tenant_id', 'resource_id', 'starts_at']);
        });
        DB::statement("alter table salon_appointment_lines add constraint salon_appointment_lines_check check (ends_at > starts_at and blocked_until >= ends_at and price_minor >= 0 and kind in ('service', 'addon'))");
        Rls::enable('salon_appointment_lines');

        // SAL-12: the walk-in queue.
        Schema::create('salon_queue', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('guest_name', 120);
            $table->string('guest_phone', 30)->nullable();
            $table->jsonb('service_ids')->default('[]');
            $table->foreignUuid('staff_id')->nullable()->constrained('salon_staff')->restrictOnDelete();
            $table->string('status', 8)->default('waiting');
            $table->foreignUuid('appointment_id')->nullable()->constrained('salon_appointments')->restrictOnDelete();
            $table->timestampTz('joined_at');
            $table->timestampTz('seated_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampTz('anonymised_at')->nullable();
            $user($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'branch_id', 'status', 'joined_at']);
            $table->index('company_id');
            $table->index('device_id');
            $table->index('client_id');
            $table->index('staff_id');
            $table->index('appointment_id');
        });
        DB::statement("alter table salon_queue add constraint salon_queue_check check (status in ('waiting', 'seated', 'left'))");
        Rls::enable('salon_queue');

        // SAL-11, SAL-17: the deposit ledger of an appointment. A balance is the sum of settled deposits and restored credit less refunds and credit applied.
        Schema::create('salon_payments', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('appointment_id')->constrained('salon_appointments')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('status', 8)->default('pending');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('method_type', 20)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('reference', 120)->nullable();
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('sale_payment_id')->nullable();
            $table->string('failure', 60)->nullable();
            $user($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'appointment_id']);
            $table->index('payment_method_id');
        });
        DB::statement("alter table salon_payments add constraint salon_payments_check check (kind in ('deposit', 'refund', 'applied', 'restored') and status in ('pending', 'settled', 'failed') and amount_minor > 0)");
        DB::statement('create unique index salon_payments_sale_payment on salon_payments (tenant_id, kind, sale_payment_id) where sale_payment_id is not null');
        Rls::enable('salon_payments');

        // SAL-18: package types and the packages sold.
        Schema::create('salon_package_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('kind', 10)->default('package');
            $table->smallInteger('sessions');
            $table->smallInteger('validity_days')->nullable();
            $table->jsonb('service_ids')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('item_id');
        });
        DB::statement("alter table salon_package_types add constraint salon_package_types_check check (kind in ('package', 'membership') and sessions between 1 and 200 and (validity_days is null or validity_days between 1 and 3650))");
        DB::statement('create unique index salon_package_types_item on salon_package_types (tenant_id, company_id, item_id) where archived_at is null');
        DB::statement('create unique index salon_package_types_name on salon_package_types (tenant_id, company_id, lower(name)) where archived_at is null');
        Rls::enable('salon_package_types');

        Schema::create('salon_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('type_id')->constrained('salon_package_types')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('parties')->restrictOnDelete();
            $table->string('name', 120);
            $table->uuid('sale_id');
            $table->uuid('sale_line_id');
            $table->smallInteger('unit_no')->default(1);
            $table->smallInteger('sessions_total');
            $table->smallInteger('sessions_used')->default(0);
            $table->bigInteger('price_minor');
            $table->bigInteger('remaining_minor');
            $table->char('currency', 3);
            $table->jsonb('service_ids')->default('[]');
            $table->string('status', 10)->default('active');
            $table->date('expires_on')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sale_line_id', 'unit_no']);
            $table->index('company_id');
            $table->index('type_id');
            $table->index(['tenant_id', 'customer_id']);
        });
        DB::statement("alter table salon_packages add constraint salon_packages_check check (status in ('active', 'exhausted', 'expired', 'void') and sessions_used between 0 and sessions_total and remaining_minor between 0 and price_minor)");
        Rls::enable('salon_packages');

        Schema::create('salon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('package_id')->constrained('salon_packages')->restrictOnDelete();
            $table->uuid('appointment_id')->nullable();
            $table->uuid('sale_id');
            $table->uuid('sale_payment_id');
            $table->uuid('service_id')->nullable();
            $table->bigInteger('value_minor');
            $table->char('currency', 3);
            $table->string('status', 10)->default('redeemed');
            $table->timestampTz('redeemed_at');
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sale_payment_id']);
            $table->index('package_id');
        });
        DB::statement("alter table salon_redemptions add constraint salon_redemptions_check check (status in ('redeemed', 'reversed') and value_minor >= 0)");
        Rls::enable('salon_redemptions');

        // SAL-16 to SAL-18: credit or sales the module could not honour or match, for a person to look at.
        Schema::create('salon_exceptions', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->string('kind', 30);
            $table->string('source_key', 160);
            $table->uuid('appointment_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->uuid('package_id')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->jsonb('detail')->default('{}');
            $table->string('status', 8)->default('open');
            $table->string('note', 300)->nullable();
            $user($table, 'resolved_by');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_key']);
            $table->index('company_id');
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("alter table salon_exceptions add constraint salon_exceptions_check check (status in ('open', 'resolved'))");
        Rls::enable('salon_exceptions');

        // SAL-20: commission rules, effective-dated.
        Schema::create('salon_commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('staff_id')->nullable()->constrained('salon_staff')->restrictOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('salon_services')->restrictOnDelete();
            $table->string('applies_to', 8)->default('service');
            $table->string('kind', 8);
            $table->decimal('rate', 7, 4)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('staff_id');
            $table->index('service_id');
        });
        DB::statement("alter table salon_commission_rules add constraint salon_commission_rules_check check (
            applies_to in ('service', 'product') and kind in ('percent', 'fixed')
            and ((kind = 'percent' and rate is not null and rate between 0 and 100 and amount_minor is null)
              or (kind = 'fixed' and amount_minor is not null and amount_minor >= 0 and currency is not null and rate is null))
            and (effective_to is null or effective_to >= effective_from) and (applies_to = 'service' or service_id is null))");
        Rls::enable('salon_commission_rules');

        // SAL-23: a period's commissions, reviewed and approved before they are exported.
        Schema::create('salon_commission_batches', function (Blueprint $table) use ($user) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->string('status', 10)->default('draft');
            $user($table, 'created_by', false);
            $user($table, 'submitted_by');
            $table->timestampTz('submitted_at')->nullable();
            $user($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $user($table, 'exported_by');
            $table->timestampTz('exported_at')->nullable();
            $table->string('export_mode', 8)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table salon_commission_batches add constraint salon_commission_batches_check check (
            status in ('draft', 'submitted', 'approved', 'rejected', 'exported') and period_end >= period_start and (export_mode is null or export_mode in ('payroll', 'report')))");
        Rls::enable('salon_commission_batches');

        // SAL-21: the commission ledger. Append-only; only `batch_id` changes later (set when batched, cleared when a batch is rejected).
        Schema::create('salon_commission_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->string('kind', 8);
            $table->string('line_kind', 8)->default('service');
            $table->uuid('sale_id');
            $table->uuid('sale_line_id')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->uuid('appointment_line_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->decimal('qty', 18, 6)->default(0);
            $table->bigInteger('base_minor')->default(0);
            $table->uuid('rule_id')->nullable();
            $table->jsonb('rule_snapshot')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->uuid('reverses_id')->nullable();
            $table->string('source_type', 8);
            $table->uuid('source_id');
            $table->string('source_key', 200);
            $table->timestampTz('earned_at');
            $table->foreignUuid('batch_id')->nullable()->constrained('salon_commission_batches')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_key']);
            $table->index('company_id');
            $table->index(['tenant_id', 'staff_id', 'earned_at']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index('reverses_id');
            $table->index('batch_id');
        });
        DB::statement("alter table salon_commission_entries add constraint salon_commission_entries_check check (kind in ('earn', 'reversal') and line_kind in ('service', 'product') and source_type in ('sale', 'void', 'refund'))");
        DB::unprepared(<<<'SQL'
            create or replace function salon_entries_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
                end if;

                if (to_jsonb(new) - 'batch_id' - 'updated_at') is distinct from (to_jsonb(old) - 'batch_id' - 'updated_at') then
                    raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
                end if;

                return new;
            end;
            $$
            SQL);
        DB::statement('create trigger salon_commission_entries_guard before update or delete on salon_commission_entries for each row execute function salon_entries_guard()');
        Rls::enable('salon_commission_entries');

        Schema::create('salon_commission_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('batch_id')->constrained('salon_commission_batches')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->integer('entry_count')->default(0);
            $table->uuid('employee_id')->nullable();
            $table->string('status', 10)->default('pending');
            $table->uuid('payroll_ref')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'batch_id', 'staff_id']);
            $table->index('staff_id');
        });
        DB::statement("alter table salon_commission_payouts add constraint salon_commission_payouts_check check (amount_minor > 0 and status in ('pending', 'exported', 'reported', 'rejected'))");
        Rls::enable('salon_commission_payouts');

        // SAL-22: tips by staff member.
        Schema::create('salon_tips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->foreignUuid('staff_id')->constrained('salon_staff')->restrictOnDelete();
            $table->uuid('sale_id');
            $table->string('kind', 8)->default('tip');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('source_key', 200);
            $table->timestampTz('earned_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_key']);
            $table->index('company_id');
            $table->index(['tenant_id', 'staff_id', 'earned_at']);
        });
        DB::statement("alter table salon_tips add constraint salon_tips_check check (kind in ('tip', 'reversal'))");
        Rls::enable('salon_tips');

        // SAL-14: what was sent, once per appointment, kind and version.
        Schema::create('salon_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('appointment_id')->constrained('salon_appointments')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('dedupe', 40);
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'appointment_id', 'kind', 'dedupe']);
        });
        Rls::enable('salon_reminders');

        // SAL-15: the tenant of a branch's public booking page, by its code; nothing but the id (ADR 002).
        DB::unprepared(<<<'SQL'
            create or replace function public.salon_booking_tenant(p_code text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select b.tenant_id from public.salon_branches b
                join public.tenants t on t.id = b.tenant_id and t.status = 'active'
                where b.public_code = lower(p_code) and b.online_enabled
                limit 1
            $$;

            revoke all on function public.salon_booking_tenant(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.salon_booking_tenant(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.salon_booking_tenant(text)');

        foreach ([
            'salon_reminders', 'salon_tips', 'salon_commission_payouts', 'salon_commission_entries', 'salon_commission_batches', 'salon_commission_rules',
            'salon_exceptions', 'salon_redemptions', 'salon_packages', 'salon_package_types', 'salon_payments', 'salon_queue', 'salon_appointment_lines',
            'salon_appointments', 'salon_clients', 'salon_resources', 'salon_time_off', 'salon_staff_hours', 'salon_staff', 'salon_services',
            'salon_branches', 'salon_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('drop function if exists salon_entries_guard()');
    }
};
