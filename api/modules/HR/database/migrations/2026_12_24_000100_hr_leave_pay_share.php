<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR-12, CP-01 (owner decision 2026-10-22, docs/country-packs-research-2026-10.md):
// a paid leave type may be paid at a share of pay, as an exact fraction
// ("2/3": CD maternity leave at two thirds of pay). Null: full pay.
// HR-13: a type's yearly days may grow with seniority
// ({"every_years": 5, "days": 1}: CD Code du travail art. 141, one more
// working day per five years of service), and a type may give other
// yearly days to workers under an age ({"age": 18, "days": 18, ...research}:
// CD art. 141, read from the employee's date of birth).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->string('pay_share', 5)->nullable();
            $table->jsonb('seniority')->nullable();
            $table->jsonb('under_age')->nullable();
        });
        DB::statement("alter table hr_leave_types add constraint hr_leave_types_pay_share_check check (pay_share is null or (paid is true and pay_share ~ '^[1-9][0-9]?/[1-9][0-9]?$' and split_part(pay_share, '/', 1)::int < split_part(pay_share, '/', 2)::int))");
    }

    public function down(): void
    {
        DB::statement('alter table hr_leave_types drop constraint if exists hr_leave_types_pay_share_check');
        Schema::table('hr_leave_types', fn (Blueprint $table) => $table->dropColumn(['pay_share', 'seniority', 'under_age']));
    }
};
