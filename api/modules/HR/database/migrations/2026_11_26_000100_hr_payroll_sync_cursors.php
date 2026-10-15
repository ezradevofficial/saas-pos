<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 7C closing review (docs/modules/hr.md, "Payroll-facing changes"): Payroll reads
// HR's read contracts incrementally, by `updated_at` cursor, so each source
// is indexed by company and `updated_at`; a contract carries a `version`
// (one more on every save), so a ContractChanged that arrives late never
// overwrites a newer one in Payroll's copy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contracts', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });

        foreach (['hr_employees', 'hr_contracts', 'hr_leave_requests', 'hr_attendance_periods'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->index(['tenant_id', 'company_id', 'updated_at'], "{$name}_updated");
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_employees', 'hr_contracts', 'hr_leave_requests', 'hr_attendance_periods'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex("{$name}_updated");
            });
        }

        Schema::table('hr_contracts', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
