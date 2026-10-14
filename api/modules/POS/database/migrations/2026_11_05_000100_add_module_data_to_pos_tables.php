<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LOY-27 (docs/modules/loyalty.md, "POS schema change", approved by the
// owner on 2026-10-14): generic places for other modules' data on a sale,
// stored as the till sent it and passed on in the POS events.
//
// - pos_sales.extensions: {module: {...}} (e.g. the coupons used)
// - pos_sale_lines.adjustments: [{source, reference, amount_minor}], the
//   discount's provenance (e.g. a promotion)
// - pos_sale_payments.tender, pos_refund_payments.tender: the tender
//   provider's reference (a gift card proof, points)
//
// M1: safe on busy tables. Nullable columns without defaults are a
// catalogue change only; a lock_timeout makes the migration give up
// rather than queue behind long transactions; the CHECK constraints are
// added NOT VALID (no scan under the lock) and validated by the next
// migration outside a transaction.
return new class extends Migration
{
    public const CHECKS = [
        'pos_sales' => ['pos_sales_extensions_check', 'extensions', 'object'],
        'pos_sale_lines' => ['pos_sale_lines_adjustments_check', 'adjustments', 'array'],
        'pos_sale_payments' => ['pos_sale_payments_tender_check', 'tender', 'object'],
        'pos_refund_payments' => ['pos_refund_payments_tender_check', 'tender', 'object'],
    ];

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        foreach (self::CHECKS as $table => [$constraint, $column, $type]) {
            DB::statement("alter table {$table} add column {$column} jsonb");
            DB::statement("alter table {$table} add constraint {$constraint} check ({$column} is null or jsonb_typeof({$column}) = '{$type}') not valid");
        }
    }

    public function down(): void
    {
        foreach (self::CHECKS as $table => [, $column]) {
            DB::statement("alter table {$table} drop column {$column}");
        }
    }
};
