<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR module (docs/modules/hr.md). Every table is the tenant's, under forced
// row-level security (TEN-01), with UUID v7 keys. Money is bigint minor
// units with its currency (ADR 003); leave days numeric(8,2). Identifier
// values, pay account numbers and medical notes are stored encrypted by the
// application (HR-10): the database never holds them in clear. The leave
// ledger and the punches are append-only (triggers refuse deletes and every
// change except a punch's void and its flags). Users are referenced by
// (tenant_id, id). Nothing is deleted (TEN-06): employees are terminated,
// setup rows archived.
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

        $days = fn (Blueprint $table, string $column) => $table->decimal($column, 8, 2);

        // HR-08: positions of a company.
        Schema::create('hr_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('title', 150);
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('grade', 40)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement('create unique index hr_positions_code on hr_positions (tenant_id, company_id, lower(code)) where archived_at is null');
        Rls::enable('hr_positions');

        // HR-02, HR-05, HR-06: employees.
        Schema::create('hr_employees', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('hr_positions')->restrictOnDelete();
            $table->uuid('reports_to_id')->nullable();
            $userRef($table, 'user_id');
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('work_email', 254)->nullable();
            $table->string('work_phone', 32)->nullable();
            $table->string('personal_email', 254)->nullable();
            $table->string('personal_phone', 32)->nullable();
            $table->jsonb('address')->nullable();
            $table->jsonb('emergency_contacts')->default('[]');
            // HR-10: encrypted by the application.
            $table->text('medical_notes')->nullable();
            $table->date('hire_date');
            $table->string('status', 20);
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 30)->nullable();
            $table->text('termination_note')->nullable();
            $table->jsonb('clock_methods')->default('[]');
            $table->string('biometric_ref', 64)->nullable();
            $table->jsonb('custom')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'reports_to_id']);
        });
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->foreign('reports_to_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("alter table hr_employees add constraint hr_employees_check check (status in ('active', 'terminated') and (gender is null or gender in ('female', 'male', 'other')) and (status = 'active' or termination_date is not null) and (termination_reason is null or termination_reason in ('resignation', 'dismissal', 'end_of_contract', 'retirement', 'redundancy', 'death', 'other')) and jsonb_typeof(emergency_contacts) = 'array' and jsonb_typeof(clock_methods) = 'array' and reports_to_id is distinct from id)");
        DB::statement('create unique index hr_employees_number on hr_employees (tenant_id, company_id, lower(number))');
        DB::statement('create unique index hr_employees_user on hr_employees (tenant_id, user_id) where user_id is not null');
        DB::statement('create unique index hr_employees_biometric on hr_employees (tenant_id, company_id, biometric_ref) where biometric_ref is not null');
        DB::statement('create index hr_employees_custom on hr_employees using gin (custom)');
        Rls::enable('hr_employees');

        // HR-03, HR-10: identifiers from the country pack, encrypted.
        Schema::create('hr_employee_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->text('value');
            $table->string('value_hint', 8);
            $table->char('value_digest', 64);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'employee_id', 'type']);
            $table->unique(['tenant_id', 'company_id', 'type', 'value_digest']);
        });
        Rls::enable('hr_employee_identifiers');

        // HR-04, HR-10: pay accounts, numbers encrypted.
        Schema::create('hr_pay_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('provider', 100);
            $table->string('branch_code', 40)->nullable();
            $table->string('account_name', 150);
            $table->text('account_number');
            $table->string('account_hint', 8);
            $table->char('currency', 3);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id']);
        });
        DB::statement("alter table hr_pay_accounts add constraint hr_pay_accounts_check check (kind in ('bank', 'mobile_money'))");
        DB::statement('create unique index hr_pay_accounts_primary on hr_pay_accounts (tenant_id, employee_id) where is_primary and archived_at is null');
        Rls::enable('hr_pay_accounts');

        // HR-07: contracts.
        Schema::create('hr_contracts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->foreignUuid('position_id')->nullable()->constrained('hr_positions')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('pay_basis', 10);
            $table->bigInteger('pay_minor');
            $table->char('currency', 3);
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->string('status', 10);
            $table->text('end_reason')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'employee_id', 'start_date']);
        });
        DB::statement("alter table hr_contracts add constraint hr_contracts_check check (type in ('permanent', 'fixed_term', 'casual', 'internship', 'consultant') and pay_basis in ('monthly', 'daily', 'hourly') and status in ('active', 'ended') and pay_minor >= 0 and (end_date is null or end_date >= start_date) and (probation_end_date is null or probation_end_date >= start_date) and (type <> 'fixed_term' or end_date is not null))");
        Rls::enable('hr_contracts');

        // HR-12: leave types per company (statutory ones copied from the pack).
        Schema::create('hr_leave_types', function (Blueprint $table) use ($days) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('statutory_key', 40)->nullable();
            $table->boolean('paid')->default(true);
            $table->string('accrual', 10);
            $days($table, 'entitlement_days')->nullable();
            $table->boolean('needs_confirmation')->default(false);
            $table->string('count_basis', 20);
            $days($table, 'carry_over_max_days')->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->boolean('requires_document')->default(false);
            // HR-13: accrual starts on this date (earlier entitlement is an opening adjustment).
            $table->date('accrues_from')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table hr_leave_types add constraint hr_leave_types_check check (accrual in ('none', 'annual', 'monthly') and count_basis in ('working_days', 'calendar_days') and (entitlement_days is null or entitlement_days >= 0) and (carry_over_max_days is null or carry_over_max_days >= 0) and (needs_confirmation or entitlement_days is not null or accrual = 'none'))");
        DB::statement('create unique index hr_leave_types_code on hr_leave_types (tenant_id, company_id, lower(code)) where archived_at is null');
        DB::statement('create unique index hr_leave_types_statutory on hr_leave_types (tenant_id, company_id, statutory_key) where statutory_key is not null');
        Rls::enable('hr_leave_types');

        // HR-14: leave requests.
        Schema::create('hr_leave_requests', function (Blueprint $table) use ($userRef, $days) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('start_half')->default(false);
            $table->boolean('end_half')->default(false);
            $days($table, 'days');
            $table->jsonb('dates');
            $table->text('reason')->nullable();
            $table->string('status', 20);
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id', 'start_date']);
            $table->index(['tenant_id', 'company_id', 'start_date']);
        });
        DB::statement("alter table hr_leave_requests add constraint hr_leave_requests_check check (status in ('pending', 'approved', 'rejected', 'cancelled') and end_date >= start_date and days > 0)");
        Rls::enable('hr_leave_requests');

        // HR-13: the leave ledger, append-only.
        Schema::create('hr_leave_entries', function (Blueprint $table) use ($userRef, $days) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->string('kind', 20);
            $days($table, 'days');
            $table->date('effective_on');
            $table->string('key', 80);
            $table->foreignUuid('leave_request_id')->nullable()->constrained('hr_leave_requests')->restrictOnDelete();
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(['tenant_id', 'employee_id', 'leave_type_id', 'key']);
            $table->index(['tenant_id', 'employee_id', 'leave_type_id', 'effective_on']);
        });
        DB::statement("alter table hr_leave_entries add constraint hr_leave_entries_check check (kind in ('accrual', 'adjustment', 'taken', 'reversal', 'carry_over', 'expiry') and days <> 0)");
        Rls::enable('hr_leave_entries');

        // HR-16: shifts and rotations.
        Schema::create('hr_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('break_minutes')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement('alter table hr_shifts add constraint hr_shifts_check check (break_minutes between 0 and 720 and start_time <> end_time)');
        DB::statement('create unique index hr_shifts_code on hr_shifts (tenant_id, company_id, lower(code)) where archived_at is null');
        Rls::enable('hr_shifts');

        Schema::create('hr_rotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->integer('cycle_days');
            $table->jsonb('days');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table hr_rotations add constraint hr_rotations_check check (cycle_days between 1 and 56 and jsonb_typeof(days) = 'array' and jsonb_array_length(days) = cycle_days)");
        Rls::enable('hr_rotations');

        // HR-17: rosters and their members.
        Schema::create('hr_rosters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('name', 120);
            $table->foreignUuid('rotation_id')->constrained('hr_rotations')->restrictOnDelete();
            $table->date('anchor_date');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        Rls::enable('hr_rosters');

        Schema::create('hr_roster_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('roster_id')->constrained('hr_rosters')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->integer('offset_days')->default(0);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'roster_id', 'employee_id']);
        });
        DB::statement('alter table hr_roster_members add constraint hr_roster_members_check check (offset_days >= 0 and (to_date is null or from_date is null or to_date >= from_date))');
        Rls::enable('hr_roster_members');

        // HR-17, HR-18: planned shifts.
        Schema::create('hr_roster_shifts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignUuid('shift_id')->constrained('hr_shifts')->restrictOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->integer('break_minutes')->default(0);
            $table->string('source', 20);
            $table->foreignUuid('roster_id')->nullable()->constrained('hr_rosters')->restrictOnDelete();
            $table->string('status', 20);
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'date']);
        });
        DB::statement("alter table hr_roster_shifts add constraint hr_roster_shifts_check check (source in ('generated', 'manual', 'swap') and status in ('planned', 'cancelled') and end_at > start_at)");
        DB::statement("create unique index hr_roster_shifts_once on hr_roster_shifts (tenant_id, employee_id, date) where status = 'planned'");
        Rls::enable('hr_roster_shifts');

        // HR-19: shift swaps.
        Schema::create('hr_shift_swaps', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('roster_shift_id')->constrained('hr_roster_shifts')->restrictOnDelete();
            $table->foreignUuid('peer_employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('peer_roster_shift_id')->nullable()->constrained('hr_roster_shifts')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 20);
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'answered_by');
            $table->timestampTz('answered_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
        DB::statement("alter table hr_shift_swaps add constraint hr_shift_swaps_check check (status in ('awaiting_peer', 'pending', 'approved', 'rejected', 'declined', 'cancelled') and employee_id <> peer_employee_id)");
        Rls::enable('hr_shift_swaps');

        // HR-20: time rules, effective-dated.
        Schema::create('hr_time_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('daily_overtime_after_minutes')->nullable();
            $table->integer('weekly_overtime_after_minutes')->nullable();
            $table->time('night_start')->nullable();
            $table->time('night_end')->nullable();
            $table->integer('late_grace_minutes')->default(0);
            $table->integer('early_leave_grace_minutes')->default(0);
            $table->jsonb('rest_days')->default('[]');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'effective_from']);
        });
        DB::statement("alter table hr_time_rules add constraint hr_time_rules_check check ((effective_to is null or effective_to >= effective_from) and (daily_overtime_after_minutes is null or daily_overtime_after_minutes between 0 and 1440) and (weekly_overtime_after_minutes is null or weekly_overtime_after_minutes between 0 and 10080) and ((night_start is null) = (night_end is null)) and late_grace_minutes between 0 and 240 and early_leave_grace_minutes between 0 and 240 and jsonb_typeof(rest_days) = 'array')");
        DB::statement('create extension if not exists btree_gist with schema public');
        DB::statement("alter table hr_time_rules add constraint hr_time_rules_no_overlap exclude using gist (tenant_id with =, company_id with =, daterange(effective_from, effective_to, '[]') with &&)");
        Rls::enable('hr_time_rules');

        // HR-24: biometric attendance devices (tokens are core personal access tokens).
        Schema::create('hr_attendance_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('vendor', 120)->nullable();
            $table->string('status', 20);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table hr_attendance_devices add constraint hr_attendance_devices_check check (status in ('active', 'suspended', 'retired'))");
        Rls::enable('hr_attendance_devices');

        // HR-26: attendance periods.
        Schema::create('hr_attendance_periods', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10);
            $userRef($table, 'closed_by');
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('totals')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'start_date']);
        });
        DB::statement("alter table hr_attendance_periods add constraint hr_attendance_periods_check check (status in ('open', 'closed') and end_date >= start_date and end_date - start_date <= 92)");
        DB::statement("alter table hr_attendance_periods add constraint hr_attendance_periods_no_overlap exclude using gist (tenant_id with =, company_id with =, daterange(start_date, end_date, '[]') with &&)");
        Rls::enable('hr_attendance_periods');

        // HR-22: punches, append-only.
        Schema::create('hr_punches', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 5);
            $table->timestampTz('occurred_at', 6);
            $table->string('source', 10);
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('attendance_device_id')->nullable()->constrained('hr_attendance_devices')->restrictOnDelete();
            $table->string('external_id', 100)->nullable();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $userRef($table, 'recorded_by');
            $table->jsonb('flags')->default('[]');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('voided_at')->nullable();
            $userRef($table, 'voided_by');
            $table->text('void_reason')->nullable();

            $table->index(['tenant_id', 'employee_id', 'occurred_at']);
            $table->index(['tenant_id', 'company_id', 'occurred_at']);
        });
        DB::statement("alter table hr_punches add constraint hr_punches_check check (kind in ('in', 'out') and source in ('web', 'till', 'device', 'manual') and jsonb_typeof(flags) = 'array')");
        DB::statement('create unique index hr_punches_external on hr_punches (tenant_id, attendance_device_id, external_id) where external_id is not null');
        Rls::enable('hr_punches');

        // HR-25: timesheet days.
        Schema::create('hr_attendance_days', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignUuid('roster_shift_id')->nullable()->constrained('hr_roster_shifts')->restrictOnDelete();
            $table->timestampTz('first_in')->nullable();
            $table->timestampTz('last_out')->nullable();
            foreach (['worked', 'regular', 'overtime', 'night', 'holiday', 'rest_day', 'late', 'early_leave'] as $minutes) {
                $table->integer("{$minutes}_minutes")->default(0);
            }
            $table->string('status', 20);
            $table->jsonb('exceptions')->default('[]');
            $table->timestampTz('approved_at')->nullable();
            $userRef($table, 'approved_by');
            $table->foreignUuid('period_id')->nullable()->constrained('hr_attendance_periods')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'employee_id', 'work_date']);
            $table->index(['tenant_id', 'company_id', 'work_date']);
        });
        DB::statement("alter table hr_attendance_days add constraint hr_attendance_days_check check (status in ('present', 'absent', 'leave', 'holiday', 'rest', 'incomplete') and worked_minutes = regular_minutes + overtime_minutes and jsonb_typeof(exceptions) = 'array')");
        Rls::enable('hr_attendance_days');

        // HR-23: clock-in PINs (the core PIN scheme, never the PIN).
        Schema::create('hr_clock_pins', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->text('pin_hash');
            $table->string('pin_salt', 64);
            $table->integer('pin_iterations');
            $table->text('pin_key');
            $table->smallInteger('pin_digits');
            $table->integer('version');
            $userRef($table, 'set_by');
            $table->timestampTz('set_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'employee_id']);
        });
        Rls::enable('hr_clock_pins');

        // HR-27: documents on employees.
        Schema::create('hr_documents', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('category', 20);
            $table->string('title', 200);
            $table->date('expires_on')->nullable();
            $table->string('disk', 40);
            $table->string('path', 500)->unique();
            $table->string('file_name', 255);
            $table->string('mime', 120);
            $table->bigInteger('size');
            $userRef($table, 'uploaded_by', nullable: false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id']);
        });
        DB::statement("alter table hr_documents add constraint hr_documents_check check (category in ('contract', 'identity', 'certificate', 'medical', 'disciplinary', 'other'))");
        Rls::enable('hr_documents');

        // HR-28: checklists.
        Schema::create('hr_checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->jsonb('items');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table hr_checklist_templates add constraint hr_checklist_templates_check check (kind in ('onboarding', 'offboarding') and jsonb_typeof(items) = 'array')");
        DB::statement('create unique index hr_checklist_templates_default on hr_checklist_templates (tenant_id, company_id, kind) where is_default and archived_at is null');
        Rls::enable('hr_checklist_templates');

        Schema::create('hr_checklists', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('template_id')->nullable()->constrained('hr_checklist_templates')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('status', 20);
            $table->timestampTz('completed_at')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id']);
        });
        DB::statement("alter table hr_checklists add constraint hr_checklists_check check (kind in ('onboarding', 'offboarding') and status in ('open', 'completed'))");
        Rls::enable('hr_checklists');

        Schema::create('hr_checklist_items', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('checklist_id')->constrained('hr_checklists')->restrictOnDelete();
            $table->integer('position');
            $table->string('title', 200);
            $table->date('due_on')->nullable();
            $table->string('role_key', 60)->nullable();
            $userRef($table, 'done_by');
            $table->timestampTz('done_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'checklist_id']);
        });
        Rls::enable('hr_checklist_items');

        // HR-29: disciplinary cases.
        Schema::create('hr_disciplinary_cases', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('category', 20);
            $table->date('incident_date');
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->string('status', 10);
            $table->text('outcome')->nullable();
            $userRef($table, 'opened_by', nullable: false);
            $userRef($table, 'closed_by');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id']);
        });
        DB::statement("alter table hr_disciplinary_cases add constraint hr_disciplinary_cases_check check (category in ('verbal_warning', 'written_warning', 'final_warning', 'suspension', 'dismissal', 'other') and status in ('open', 'closed'))");
        Rls::enable('hr_disciplinary_cases');

        // HR-30: appraisals.
        Schema::create('hr_appraisal_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 10);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table hr_appraisal_cycles add constraint hr_appraisal_cycles_check check (status in ('draft', 'open', 'closed') and period_end >= period_start)");
        Rls::enable('hr_appraisal_cycles');

        Schema::create('hr_appraisals', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('cycle_id')->constrained('hr_appraisal_cycles')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $userRef($table, 'reviewer_id');
            $table->jsonb('goals')->default('[]');
            $table->smallInteger('self_rating')->nullable();
            $table->text('self_comments')->nullable();
            $table->smallInteger('manager_rating')->nullable();
            $table->text('manager_comments')->nullable();
            $table->decimal('overall_rating', 4, 2)->nullable();
            $table->string('status', 20);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'cycle_id', 'employee_id']);
        });
        DB::statement("alter table hr_appraisals add constraint hr_appraisals_check check (status in ('self_review', 'manager_review', 'completed', 'acknowledged') and (self_rating is null or self_rating between 1 and 5) and (manager_rating is null or manager_rating between 1 and 5) and jsonb_typeof(goals) = 'array')");
        Rls::enable('hr_appraisals');

        // Append-only records: the leave ledger never changes; a punch is voided once and its flags may grow.
        DB::unprepared(<<<'SQL'
            create or replace function hr_append_only() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'UPDATE' and tg_table_name = 'hr_punches'
                   and (to_jsonb(new) - 'voided_at' - 'voided_by' - 'void_reason' - 'flags') = (to_jsonb(old) - 'voided_at' - 'voided_by' - 'void_reason' - 'flags')
                   and (old.voided_at is null or (new.voided_at = old.voided_at and new.voided_by is not distinct from old.voided_by and new.void_reason is not distinct from old.void_reason)) then
                    return new;
                end if;

                raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
            end;
            $$;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        foreach (['hr_leave_entries', 'hr_punches'] as $table) {
            DB::unprepared("create trigger {$table}_append_only before update or delete on {$table} for each row execute function hr_append_only()");
            DB::unprepared("create trigger {$table}_no_truncate before truncate on {$table} for each statement execute function hr_append_only()");
            DB::statement("revoke delete, truncate on {$table} from {$runtimeRole}");
        }
    }

    public function down(): void
    {
        foreach ([
            'hr_appraisals', 'hr_appraisal_cycles', 'hr_disciplinary_cases', 'hr_checklist_items', 'hr_checklists', 'hr_checklist_templates',
            'hr_documents', 'hr_clock_pins', 'hr_attendance_days', 'hr_punches', 'hr_attendance_periods', 'hr_attendance_devices', 'hr_time_rules',
            'hr_shift_swaps', 'hr_roster_shifts', 'hr_roster_members', 'hr_rosters', 'hr_rotations', 'hr_shifts', 'hr_leave_entries',
            'hr_leave_requests', 'hr_leave_types', 'hr_contracts', 'hr_pay_accounts', 'hr_employee_identifiers', 'hr_employees', 'hr_positions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('drop function if exists hr_append_only()');
    }
};
