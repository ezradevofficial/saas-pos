<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LOY-27 (docs/modules/loyalty.md, "POS schema change"): generic places
// for other modules' data on a sale, stored as the till sent it and
// passed on in the POS events. POS reads none of it, except the discount
// amount a registered source vouches for (core DiscountSources).
//
// - pos_sales.extensions: {module: {...}} (e.g. the coupons used)
// - pos_sale_lines.adjustments: [{source, reference, amount_minor}], the
//   discount's provenance (e.g. a promotion)
// - pos_sale_payments.tender, pos_refund_payments.tender: the tender
//   provider's reference (a gift card, points) (core TenderProviders)
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table pos_sales add column extensions jsonb');
        DB::statement("alter table pos_sales add constraint pos_sales_extensions_check check (extensions is null or jsonb_typeof(extensions) = 'object')");
        DB::statement('alter table pos_sale_lines add column adjustments jsonb');
        DB::statement("alter table pos_sale_lines add constraint pos_sale_lines_adjustments_check check (adjustments is null or jsonb_typeof(adjustments) = 'array')");

        foreach (['pos_sale_payments', 'pos_refund_payments'] as $table) {
            DB::statement("alter table {$table} add column tender jsonb");
            DB::statement("alter table {$table} add constraint {$table}_tender_check check (tender is null or jsonb_typeof(tender) = 'object')");
        }
    }

    public function down(): void
    {
        DB::statement('alter table pos_sales drop column extensions');
        DB::statement('alter table pos_sale_lines drop column adjustments');
        DB::statement('alter table pos_sale_payments drop column tender');
        DB::statement('alter table pos_refund_payments drop column tender');
    }
};
