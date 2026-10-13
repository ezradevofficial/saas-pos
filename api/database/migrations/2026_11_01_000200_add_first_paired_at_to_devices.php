<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// LIM-03, LIM-04: when a device was first paired, kept when it is unpaired
// (`paired_at` is cleared). A till that has worked before is paired again,
// resumed or given a new code without the usage-limit guard, so a working
// till is never taken off sales. Filled from what is known: the current
// pairing, or the device's first bootstrap or contact.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestampTz('first_paired_at')->nullable();
        });

        DB::statement('update devices set first_paired_at = coalesce(paired_at, last_bootstrap_at, last_seen_at)');
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('first_paired_at');
        });
    }
};
