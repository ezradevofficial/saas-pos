<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Payroll module (docs/modules/payroll.md). Every table is the tenant's,
// under forced row-level security (TEN-01), with UUID v7 keys. Money is
// bigint minor units with its currency (ADR 003); rates numeric. HR's
// records are referenced by id only (Payroll keeps its own copy of what it
// pays from and never reads HR's tables, architecture rule 1). Posted runs
// and their lines are frozen, and the loan ledger is append-only
// (triggers). Nothing is deleted (TEN-06) except the lines of a run not yet
// posted, which are recomputed.
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        // PAY-02: settings per company.
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->decimal('overtime_multiplier', 8, 4)->nullable();
            $table->decimal('night_multiplier', 8, 4)->nullable();
            $table->decimal('holiday_multiplier', 8, 4)->nullable();
            $table->decimal('rest_day_multiplier', 8, 4)->nullable();
            $table->decimal('monthly_hours', 8, 2)->nullable();
            $table->decimal('monthly_days', 6, 2)->nullable();
            $table->boolean('premiums_taxable')->default(true);
            $table->boolean('premiums_contributory')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        DB::statement('alter table payroll_settings add constraint payroll_settings_check check ((overtime_multiplier is null or overtime_multiplier >= 0) and (night_multiplier is null or night_multiplier >= 1) and (holiday_multiplier is null or holiday_multiplier >= 1) and (rest_day_multiplier is null or rest_day_multiplier >= 1) and (monthly_hours is null or monthly_hours > 0) and (monthly_days is null or monthly_days > 0))');
        Rls::enable('payroll_settings');

        // PAY-03: pay groups.
        Schema::create('payroll_pay_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('frequency', 10);
            $table->date('anchor_date')->nullable();
            $table->smallInteger('pay_day')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table payroll_pay_groups add constraint payroll_pay_groups_check check (frequency in ('monthly', 'weekly', 'biweekly') and (pay_day is null or pay_day between 1 and 31) and (frequency = 'monthly' or anchor_date is not null))");
        DB::statement('create unique index payroll_pay_groups_code on payroll_pay_groups (tenant_id, company_id, lower(code)) where archived_at is null');
        Rls::enable('payroll_pay_groups');

        // PAY-04: pay items.
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('kind', 10);
            $table->boolean('taxable')->default(false);
            $table->boolean('contributory')->default(false);
            $table->boolean('pre_tax')->default(false);
            $table->boolean('insurance_premium')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("alter table payroll_items add constraint payroll_items_check check (kind in ('earning', 'deduction') and (kind = 'earning' or (not taxable and not contributory)) and (kind = 'deduction' or (not pre_tax and not insurance_premium)))");
        DB::statement('create unique index payroll_items_code on payroll_items (tenant_id, company_id, lower(code)) where archived_at is null');
        Rls::enable('payroll_items');

        // PAY-05: Payroll's copy of HR's employees, with their pay setup.
        Schema::create('payroll_employees', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // HR's employee id (no foreign key: another module's table).
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('name', 320);
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->uuid('position_id')->nullable();
            $userRef($table, 'user_id');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('pay_group_id')->nullable()->constrained('payroll_pay_groups')->restrictOnDelete();
            $table->char('pay_currency', 3)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->smallInteger('dependants')->default(0);
            $table->smallInteger('children')->default(0);
            $table->boolean('on_hold')->default(false);
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'employee_id']);
            $table->foreign('pay_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['tenant_id', 'pay_group_id']);
        });
        DB::statement("alter table payroll_employees add constraint payroll_employees_check check (status in ('active', 'terminated') and (payment_method is null or payment_method in ('bank', 'mobile_money', 'cash')) and dependants between 0 and 50 and children between 0 and 50)");
        Rls::enable('payroll_employees');

        // PAY-05: Payroll's copy of HR's contracts.
        Schema::create('payroll_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('contract_id');
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('pay_basis', 10);
            $table->bigInteger('pay_minor');
            $table->char('currency', 3);
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->string('status', 10);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'employee_id', 'start_date']);
        });
        DB::statement("alter table payroll_contracts add constraint payroll_contracts_check check (pay_basis in ('monthly', 'daily', 'hourly') and status in ('active', 'ended') and pay_minor >= 0)");
        Rls::enable('payroll_contracts');

        // PAY-13: loans and advances, and their ledger.
        Schema::create('payroll_loans', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('payroll_employee_id')->constrained('payroll_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('reference', 80)->nullable();
            $table->bigInteger('principal_minor');
            $table->char('currency', 3);
            $table->date('disbursed_on');
            $table->bigInteger('instalment_minor');
            $table->date('starts_on');
            $table->boolean('settle_next_run')->default(false);
            $table->string('status', 10);
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'payroll_employee_id', 'status']);
        });
        DB::statement("alter table payroll_loans add constraint payroll_loans_check check (kind in ('loan', 'advance') and status in ('active', 'settled', 'cancelled') and principal_minor > 0 and instalment_minor > 0)");
        Rls::enable('payroll_loans');

        // PAY-14: runs.
        Schema::create('payroll_runs', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('pay_group_id')->constrained('payroll_pay_groups')->restrictOnDelete();
            $table->string('kind', 12);
            $table->uuid('corrects_run_id')->nullable();
            $table->string('correction_mode', 12)->nullable();
            $table->jsonb('correction_employee_ids')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status', 12);
            $table->jsonb('totals')->nullable();
            $table->jsonb('missing')->nullable();
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $userRef($table, 'calculated_by');
            $table->timestampTz('calculated_at')->nullable();
            $userRef($table, 'submitted_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $userRef($table, 'posted_by');
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'period_start']);
            $table->index(['tenant_id', 'pay_group_id', 'period_start']);
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreign('corrects_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();
        });
        DB::statement("alter table payroll_runs add constraint payroll_runs_check check (kind in ('regular', 'correction') and status in ('draft', 'calculated', 'pending', 'approved', 'posted', 'cancelled') and period_end >= period_start and (kind = 'regular' or (corrects_run_id is not null and correction_mode in ('reverse', 'recalculate'))) and (status <> 'posted' or posted_at is not null))");
        DB::statement("create unique index payroll_runs_regular on payroll_runs (tenant_id, pay_group_id, period_start) where kind = 'regular' and status <> 'cancelled'");
        DB::statement('create unique index payroll_runs_number on payroll_runs (tenant_id, company_id, number)');
        Rls::enable('payroll_runs');

        // PAY-09 to PAY-15: one line per employee and run.
        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignUuid('payroll_employee_id')->constrained('payroll_employees')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('number', 80);
            $table->string('name', 320);
            $table->char('pay_currency', 3);
            $table->char('statutory_currency', 3)->nullable();
            $table->char('base_currency', 3);
            $table->jsonb('fx')->nullable();
            $table->bigInteger('basic_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('gross_statutory_minor')->default(0);
            $table->bigInteger('taxable_statutory_minor')->default(0);
            $table->bigInteger('statutory_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('loans_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('employer_minor')->default(0);
            $table->bigInteger('gross_base_minor')->default(0);
            $table->bigInteger('net_base_minor')->default(0);
            $table->bigInteger('employer_base_minor')->default(0);
            $table->jsonb('earnings')->default('[]');
            $table->jsonb('deductions')->default('[]');
            $table->jsonb('statutory')->default('[]');
            $table->jsonb('loans')->default('[]');
            $table->jsonb('inputs')->default('{}');
            $table->jsonb('variance')->nullable();
            $table->jsonb('errors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'run_id', 'payroll_employee_id']);
            $table->index(['tenant_id', 'payroll_employee_id']);
        });
        Rls::enable('payroll_run_lines');

        // PAY-06: recurring and one-off items of an employee.
        Schema::create('payroll_employee_items', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('payroll_employee_id')->constrained('payroll_employees')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('payroll_items')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('recurrence', 10);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->date('period_date')->nullable();
            $table->foreignUuid('consumed_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'payroll_employee_id']);
        });
        DB::statement("alter table payroll_employee_items add constraint payroll_employee_items_check check (recurrence in ('recurring', 'once') and amount_minor > 0 and (recurrence = 'once' or from_date is not null) and (recurrence = 'recurring' or period_date is not null) and (to_date is null or from_date is null or to_date >= from_date))");
        Rls::enable('payroll_employee_items');

        // PAY-11: unpaid leave days from HR.
        Schema::create('payroll_leave_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->date('date');
            $table->decimal('fraction', 3, 2);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'request_id', 'date']);
            $table->index(['tenant_id', 'employee_id', 'date']);
        });
        Rls::enable('payroll_leave_days');

        // PAY-12: closed attendance periods from HR.
        Schema::create('payroll_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('period_id');
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('worked_minutes')->default(0);
            $table->integer('regular_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->integer('night_minutes')->default(0);
            $table->integer('holiday_minutes')->default(0);
            $table->integer('rest_day_minutes')->default(0);
            $table->integer('days_present')->default(0);
            $table->foreignUuid('paid_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'period_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id', 'end_date']);
        });
        Rls::enable('payroll_attendance');

        // PAY-13: the loan ledger, append-only.
        Schema::create('payroll_loan_entries', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('loan_id')->constrained('payroll_loans')->restrictOnDelete();
            $table->string('kind', 12);
            $table->bigInteger('amount_minor');
            $table->foreignUuid('run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->date('entry_date');
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['tenant_id', 'loan_id']);
        });
        DB::statement("alter table payroll_loan_entries add constraint payroll_loan_entries_check check (kind in ('disbursement', 'repayment', 'settlement', 'reversal') and amount_minor <> 0)");
        Rls::enable('payroll_loan_entries');

        // PAY-19: payslips.
        Schema::create('payroll_payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignUuid('run_line_id')->constrained('payroll_run_lines')->restrictOnDelete();
            $table->foreignUuid('payroll_employee_id')->constrained('payroll_employees')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('number', 170);
            $table->timestampTz('issued_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'run_line_id']);
            $table->index(['tenant_id', 'employee_id']);
        });
        Rls::enable('payroll_payslips');

        // PAY-21: statutory returns generated (their files are rebuilt on demand).
        Schema::create('payroll_returns', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('run_ids')->default('[]');
            $table->jsonb('totals')->default('{}');
            $table->integer('employee_count')->default(0);
            $table->boolean('needs_confirmation')->default(true);
            $userRef($table, 'generated_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'period_start']);
        });
        Rls::enable('payroll_returns');

        // PAY-17: a posted run and its lines never change; lines of a posted run are never removed.
        // PAY-13: loan entries are append-only.
        DB::unprepared(<<<'SQL'
            create or replace function payroll_run_locked() returns trigger
            language plpgsql as $$
            declare
                locked boolean;
            begin
                if tg_table_name = 'payroll_runs' then
                    if tg_op = 'DELETE' then
                        raise exception 'payroll runs are never deleted' using errcode = 'insufficient_privilege';
                    end if;
                    if old.status = 'posted' then
                        raise exception 'a posted payroll run is locked' using errcode = 'insufficient_privilege';
                    end if;
                    return new;
                end if;

                select r.status = 'posted' into locked from payroll_runs r where r.id = (case when tg_op = 'INSERT' then new.run_id else old.run_id end);
                if coalesce(locked, false) then
                    raise exception 'the lines of a posted payroll run are locked' using errcode = 'insufficient_privilege';
                end if;
                if tg_op = 'DELETE' then
                    return old;
                end if;
                return new;
            end;
            $$;

            create or replace function payroll_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
            end;
            $$;
            SQL);

        DB::unprepared('create trigger payroll_runs_locked before update or delete on payroll_runs for each row execute function payroll_run_locked()');
        DB::unprepared('create trigger payroll_run_lines_locked before insert or update or delete on payroll_run_lines for each row execute function payroll_run_locked()');
        DB::unprepared('create trigger payroll_loan_entries_append_only before update or delete on payroll_loan_entries for each row execute function payroll_append_only()');
        DB::unprepared('create trigger payroll_loan_entries_no_truncate before truncate on payroll_loan_entries for each statement execute function payroll_append_only()');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke delete, truncate on payroll_loan_entries from {$runtimeRole}");
        DB::statement("revoke delete, truncate on payroll_runs from {$runtimeRole}");
    }

    public function down(): void
    {
        foreach ([
            'payroll_returns', 'payroll_payslips', 'payroll_loan_entries', 'payroll_attendance', 'payroll_leave_days', 'payroll_employee_items',
            'payroll_run_lines', 'payroll_runs', 'payroll_loans', 'payroll_contracts', 'payroll_employees', 'payroll_items', 'payroll_pay_groups', 'payroll_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('drop function if exists payroll_run_locked()');
        DB::statement('drop function if exists payroll_append_only()');
    }
};
