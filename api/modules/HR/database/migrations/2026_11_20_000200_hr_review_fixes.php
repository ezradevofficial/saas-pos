<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR review fixes (docs/modules/hr.md): where employees worked over time
// (queued offline punches of a moved employee, HR-23), each punch's work
// date fixed when it is recorded (HR-22, HR-26), the shifts a swap was asked
// on as they were then (HR-19), and no `rest` timesheet status.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'employee_id', 'effective_from']);
        });
        DB::statement('alter table hr_employee_places add constraint hr_employee_places_check check (effective_to is null or effective_to >= effective_from)');
        Rls::enable('hr_employee_places');

        // Every employee's place so far, from their hire date.
        DB::statement('insert into hr_employee_places (id, tenant_id, employee_id, company_id, branch_id, location_id, effective_from, created_at, updated_at)
            select gen_random_uuid(), tenant_id, id, company_id, branch_id, location_id, hire_date, now(), now() from hr_employees');

        Schema::table('hr_punches', function (Blueprint $table) {
            $table->date('work_date')->nullable();
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        // HR-19: a planned shift's version rises with every change; a swap keeps the versions it was asked on.
        Schema::table('hr_roster_shifts', function (Blueprint $table) {
            $table->integer('version')->default(1);
        });

        Schema::table('hr_shift_swaps', function (Blueprint $table) {
            $table->integer('shift_version')->nullable();
            $table->integer('peer_shift_version')->nullable();
        });

        DB::statement('alter table hr_attendance_days drop constraint hr_attendance_days_check');
        DB::statement("alter table hr_attendance_days add constraint hr_attendance_days_check check (status in ('present', 'absent', 'leave', 'holiday', 'incomplete') and worked_minutes = regular_minutes + overtime_minutes and jsonb_typeof(exceptions) = 'array')");
    }

    public function down(): void
    {
        DB::statement('alter table hr_attendance_days drop constraint hr_attendance_days_check');
        DB::statement("alter table hr_attendance_days add constraint hr_attendance_days_check check (status in ('present', 'absent', 'leave', 'holiday', 'rest', 'incomplete') and worked_minutes = regular_minutes + overtime_minutes and jsonb_typeof(exceptions) = 'array')");

        Schema::table('hr_shift_swaps', function (Blueprint $table) {
            $table->dropColumn(['shift_version', 'peer_shift_version']);
        });

        Schema::table('hr_roster_shifts', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('hr_punches', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'employee_id', 'work_date']);
            $table->dropColumn('work_date');
        });

        Schema::dropIfExists('hr_employee_places');
    }
};
