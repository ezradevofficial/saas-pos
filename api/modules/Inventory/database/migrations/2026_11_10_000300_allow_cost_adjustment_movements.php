<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// INV-25 (owner decision 2026-10-14): the ledger takes value-only
// `cost_adjustment` movements (landed costs allocated after a receipt,
// PRC-17). Only that kind may carry a zero quantity; every other movement
// still moves stock. The ledger stays append-only.
//
// Stage 7B closing review: kinds live in `inventory_movement_kinds`; this
// migration inserts (and on rollback deletes) only its own row. On a
// database whose ledger still has the old kind check, it adds its kind to
// that check, keeping the kinds other migrations added; 2026_11_12_000200
// then moves the check into the lookup.
return new class extends Migration
{
    private const KIND = 'cost_adjustment';

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->insertOrIgnore(['kind' => self::KIND]);
        } else {
            $this->check([...array_diff($this->checked(), [self::KIND]), self::KIND]);
        }

        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and (qty <> 0 or kind = 'cost_adjustment') and (kind <> 'cost_adjustment' or qty = 0))");
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table inventory_movements drop constraint inventory_movements_flags_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and qty <> 0)");

        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->where('kind', self::KIND)->delete();
        } else {
            $this->check(array_values(array_diff($this->checked(), [self::KIND])));
        }
    }

    /** @return list<string> the kinds the legacy check allows */
    private function checked(): array
    {
        $definition = (string) DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'inventory_movements'::regclass and conname = 'inventory_movements_kind_check'");
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param list<string> $kinds */
    private function check(array $kinds): void
    {
        DB::statement('alter table inventory_movements drop constraint if exists inventory_movements_kind_check');
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('".implode("', '", $kinds)."'))");
    }
};
