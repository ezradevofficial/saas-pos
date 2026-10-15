<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Payroll review fixes (docs/modules/payroll.md, decisions 21 to 28): what a
// run was calculated from (the pack's source and hash), and at most one open
// correction per pay group and period.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->jsonb('inputs')->nullable();
        });

        DB::statement("create unique index payroll_runs_open_correction on payroll_runs (tenant_id, pay_group_id, period_start) where kind = 'correction' and status not in ('posted', 'cancelled')");
    }

    public function down(): void
    {
        DB::statement('drop index if exists payroll_runs_open_correction');

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('inputs');
        });
    }
};
