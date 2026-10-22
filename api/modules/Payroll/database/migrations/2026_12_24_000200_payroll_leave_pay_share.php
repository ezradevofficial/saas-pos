<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PAY-11, CP-01 (owner decision 2026-10-22): a day of paid leave at a share
// of pay (`reduced_pay`, CD maternity leave at 2/3): the run deducts the
// rest of the daily rate. `pay_share` is the exact fraction kept ("2/3").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_leave_days', function (Blueprint $table) {
            $table->string('pay_share', 5)->nullable();
        });
        DB::statement('alter table payroll_leave_days drop constraint if exists payroll_leave_days_kind_check');
        DB::statement("alter table payroll_leave_days add constraint payroll_leave_days_kind_check check (kind in ('unpaid', 'pay_unconfirmed', 'half_pay', 'reduced_pay'))");
        DB::statement("alter table payroll_leave_days add constraint payroll_leave_days_pay_share_check check ((kind = 'reduced_pay') = (pay_share is not null) and (pay_share is null or pay_share ~ '^[1-9][0-9]?/[1-9][0-9]?$'))");
    }

    public function down(): void
    {
        DB::statement('alter table payroll_leave_days drop constraint if exists payroll_leave_days_pay_share_check');
        DB::statement("update payroll_leave_days set kind = 'pay_unconfirmed', pay_share = null where kind = 'reduced_pay'");
        DB::statement('alter table payroll_leave_days drop constraint if exists payroll_leave_days_kind_check');
        DB::statement("alter table payroll_leave_days add constraint payroll_leave_days_kind_check check (kind in ('unpaid', 'pay_unconfirmed', 'half_pay'))");
        Schema::table('payroll_leave_days', fn (Blueprint $table) => $table->dropColumn('pay_share'));
    }
};
