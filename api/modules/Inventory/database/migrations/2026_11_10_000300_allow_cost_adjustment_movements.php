<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// INV-25 (owner decision 2026-10-14): the ledger takes value-only
// `cost_adjustment` movements (landed costs allocated after a receipt,
// PRC-17). Only that kind may carry a zero quantity; every other movement
// still moves stock. The ledger stays append-only.
//
// The kind check is rebuilt from the kinds it already allows plus
// `cost_adjustment`, so kinds other migrations added (Stores' `store_issue`
// and `store_return`, 2026_11_10_000200) are kept.
return new class extends Migration
{
    private const KIND = 'cost_adjustment';

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        $this->kinds([...array_diff($this->currentKinds(), [self::KIND]), self::KIND]);
        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and (qty <> 0 or kind = 'cost_adjustment') and (kind <> 'cost_adjustment' or qty = 0))");
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and qty <> 0)");
        $this->kinds(array_values(array_diff($this->currentKinds(), [self::KIND])));
    }

    /** @return list<string> the kinds the check allows now */
    private function currentKinds(): array
    {
        $definition = (string) DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'inventory_movements'::regclass and conname = 'inventory_movements_kind_check'");
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param list<string> $kinds */
    private function kinds(array $kinds): void
    {
        DB::statement('alter table inventory_movements drop constraint inventory_movements_kind_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('".implode("', '", $kinds)."'))");
    }
};
