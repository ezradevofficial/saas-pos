<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CP-01 (owner decision 2026-10-22, docs/country-packs-research-2026-10.md):
// a holiday that falls on a Sunday may move, as the country's law says.
// `next_free_day` (KE Public Holidays Act s. 4): the first following day
// that is not itself a public holiday is the holiday instead, and the
// Sunday is not. Null: the holiday stays on its date.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->string('sunday_rule', 20)->nullable();
        });
        DB::statement("alter table public_holidays add constraint public_holidays_sunday_rule_check check (sunday_rule is null or sunday_rule = 'next_free_day')");
    }

    public function down(): void
    {
        DB::statement('alter table public_holidays drop constraint if exists public_holidays_sunday_rule_check');
        Schema::table('public_holidays', fn (Blueprint $table) => $table->dropColumn('sunday_rule'));
    }
};
