<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// INV-15, INV-25 (stage 7B closing review): a receipt movement keeps the
// part of its value that is landed cost (freight, duty: allocated to the
// GRN line before it posted), in the company's base currency, so
// Accounting credits the goods to GRNI and the charges to the landed cost
// clearing account (ACC-27). Zero for every other movement.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->bigInteger('landed_minor')->default(0);
        });
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('landed_minor');
        });
    }
};
