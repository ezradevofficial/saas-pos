<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PAY-04, PAY-07, PAY-08 (researched pack values, docs/country-packs-research-2026-10.md):
// - a pre-tax deduction may name the statutory category whose limit applies
//   to it (KE: pension with NSSF up to 30,000, post-retirement medical fund
//   15,000, mortgage interest 30,000 a month);
// - a company may mark a pack component it does not owe "not applicable"
//   (only components the pack allows, e.g. CD INPP), with who, when and why.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->string('pre_tax_category', 30)->nullable();
        });
        DB::statement("alter table payroll_items add constraint payroll_items_pre_tax_category_check check (pre_tax_category is null or (pre_tax and pre_tax_category in ('pension', 'post_retirement_medical', 'mortgage_interest')))");

        Schema::table('payroll_settings', function (Blueprint $table) {
            // {component code: {reason, marked_by, marked_at, period_from}}
            $table->jsonb('not_applicable')->nullable();
        });

        // PAY-11, CP-01: what a copied leave day is: unpaid (deducted), pay_unconfirmed (the type's pay
        // rate is not confirmed: the run is blocked) or half_pay (paid at half by statute, paid in full for now).
        Schema::table('payroll_leave_days', function (Blueprint $table) {
            $table->string('kind', 20)->default('unpaid');
        });
        DB::statement("alter table payroll_leave_days add constraint payroll_leave_days_kind_check check (kind in ('unpaid', 'pay_unconfirmed', 'half_pay'))");
    }

    public function down(): void
    {
        DB::statement('alter table payroll_leave_days drop constraint if exists payroll_leave_days_kind_check');
        Schema::table('payroll_leave_days', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('payroll_settings', fn (Blueprint $table) => $table->dropColumn('not_applicable'));
        DB::statement('alter table payroll_items drop constraint if exists payroll_items_pre_tax_category_check');
        Schema::table('payroll_items', fn (Blueprint $table) => $table->dropColumn('pre_tax_category'));
    }
};
