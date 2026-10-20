<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HR-12, HR-13, HR-14 (researched pack values, docs/country-packs-research-2026-10.md):
// - a leave type may need months of service before it is taken
//   (`qualifying_months`), give days at half pay after its full-pay days
//   (`half_pay_days`), list per-event days within its yearly days
//   (`events`), and carry the research its days come from (`research`);
// - a request records the event it is for, its days at half pay, and the
//   research of its type when it was made (CP-01).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('qualifying_months')->nullable();
            $table->decimal('half_pay_days', 7, 2)->nullable();
            $table->jsonb('events')->nullable();
            $table->jsonb('research')->nullable();
        });
        DB::statement('alter table hr_leave_types add constraint hr_leave_types_half_pay_check check (half_pay_days is null or half_pay_days >= 0)');

        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->string('event', 40)->nullable();
            $table->decimal('half_pay_days', 7, 2)->default(0);
            $table->jsonb('research')->nullable();
        });

        Schema::table('hr_leave_entries', function (Blueprint $table) {
            // CP-01: an accrual computed from researched days.
            $table->boolean('researched')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_entries', fn (Blueprint $table) => $table->dropColumn('researched'));
        Schema::table('hr_leave_requests', fn (Blueprint $table) => $table->dropColumn(['event', 'half_pay_days', 'research']));
        DB::statement('alter table hr_leave_types drop constraint if exists hr_leave_types_half_pay_check');
        Schema::table('hr_leave_types', fn (Blueprint $table) => $table->dropColumn(['qualifying_months', 'half_pay_days', 'events', 'research']));
    }
};
