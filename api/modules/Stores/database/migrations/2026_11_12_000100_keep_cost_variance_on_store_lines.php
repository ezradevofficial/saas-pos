<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// STR-09, STR-16, ACC-29 (stage 7B closing review): issue and return lines
// keep Inventory's cost variance next to their value, so Stores can raise
// StoreConsumptionValued again exactly for the Accounting backfill.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['stores_issue_lines', 'stores_return_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->bigInteger('cost_variance_minor')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['stores_issue_lines', 'stores_return_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('cost_variance_minor');
            });
        }
    }
};
