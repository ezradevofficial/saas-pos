<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// STR-08 (docs/modules/stores.md): Stores' issues to departments and
// returns to store move stock through Inventory when both are active, as
// the movement kinds `store_issue` and `store_return`.
return new class extends Migration
{
    private const KINDS = ['opening', 'opening_reversal', 'receipt', 'adjustment_in', 'adjustment_out', 'count_gain', 'count_loss', 'transfer_out', 'transfer_in', 'transfer_loss', 'sale', 'sale_return', 'sale_void'];

    public function up(): void
    {
        $this->kinds([...self::KINDS, 'store_issue', 'store_return']);
    }

    public function down(): void
    {
        $this->kinds(self::KINDS);
    }

    /** @param list<string> $kinds */
    private function kinds(array $kinds): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table inventory_movements drop constraint inventory_movements_kind_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('".implode("', '", $kinds)."'))");
    }
};
