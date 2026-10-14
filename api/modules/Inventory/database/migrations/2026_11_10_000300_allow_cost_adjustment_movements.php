<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// INV-25 (owner decision 2026-10-14): the ledger takes value-only
// `cost_adjustment` movements (landed costs allocated after a receipt,
// PRC-17). Only that kind may carry a zero quantity; every other movement
// still moves stock. The ledger stays append-only.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table inventory_movements drop constraint inventory_movements_kind_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('opening', 'opening_reversal', 'receipt', 'adjustment_in', 'adjustment_out', 'count_gain', 'count_loss', 'transfer_out', 'transfer_in', 'transfer_loss', 'sale', 'sale_return', 'sale_void', 'cost_adjustment'))");
        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and (qty <> 0 or kind = 'cost_adjustment') and (kind <> 'cost_adjustment' or qty = 0))");
    }

    public function down(): void
    {
        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and qty <> 0)");
        DB::statement('alter table inventory_movements drop constraint inventory_movements_kind_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('opening', 'opening_reversal', 'receipt', 'adjustment_in', 'adjustment_out', 'count_gain', 'count_loss', 'transfer_out', 'transfer_in', 'transfer_loss', 'sale', 'sale_return', 'sale_void'))");
    }
};
