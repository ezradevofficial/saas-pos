<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NUM-01: short codes printed in document numbers ({LOCATION}, {DEVICE}),
// as a branch's code is for {BRANCH}. Optional: a number format falls back
// to a code derived from the id while none is set.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['locations', 'devices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('code', 10)->nullable();
            });
            DB::statement("alter table {$name} add constraint {$name}_code_check check (code is null or code ~ '^[A-Z0-9]{1,10}$')");
        }
    }

    public function down(): void
    {
        foreach (['locations', 'devices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
