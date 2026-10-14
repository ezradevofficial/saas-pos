<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M1: validate the module-data CHECK constraints added NOT VALID by
// 2026_11_05_000100. VALIDATE CONSTRAINT scans with a SHARE UPDATE
// EXCLUSIVE lock (sales keep being written), outside a transaction so the
// earlier migration's locks are already released.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach ([
            'pos_sales' => 'pos_sales_extensions_check',
            'pos_sale_lines' => 'pos_sale_lines_adjustments_check',
            'pos_sale_payments' => 'pos_sale_payments_tender_check',
            'pos_refund_payments' => 'pos_refund_payments_tender_check',
        ] as $table => $constraint) {
            DB::statement("alter table {$table} validate constraint {$constraint}");
        }
    }

    public function down(): void
    {
        // Nothing to undo: a validated constraint is dropped with its column.
    }
};
