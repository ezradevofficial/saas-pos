<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MFG-15, MFG-20 (stage 7D closing review item 7): a report's receipt posts what Inventory
// received (`value_minor` per output line; null before this, when the line's cost stood for it),
// and an output Inventory took nothing in for is flagged on the report (`flags`, `not_received`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfg_report_lines', function (Blueprint $table) {
            $table->bigInteger('value_minor')->nullable();
        });
        Schema::table('mfg_reports', function (Blueprint $table) {
            $table->jsonb('flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mfg_report_lines', fn (Blueprint $table) => $table->dropColumn('value_minor'));
        Schema::table('mfg_reports', fn (Blueprint $table) => $table->dropColumn('flags'));
    }
};
