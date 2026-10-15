<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// STR-08 (docs/modules/stores.md): Stores' issues to departments and
// returns to store move stock through Inventory when both are active, as
// the movement kinds `store_issue` and `store_return`.
//
// Stage 7B closing review: kinds live in `inventory_movement_kinds`; this
// migration inserts (and on rollback deletes) only its own rows. On a
// database whose ledger still has the old kind check (it ran before the
// lookup existed), it adds its kinds to that check, keeping the others;
// 2026_11_12_000200 then moves the check into the lookup.
return new class extends Migration
{
    private const KINDS = ['store_issue', 'store_return'];

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->insertOrIgnore(array_map(fn (string $kind) => ['kind' => $kind], self::KINDS));

            return;
        }

        $this->check([...array_diff($this->checked(), self::KINDS), ...self::KINDS]);
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->whereIn('kind', self::KINDS)->delete();

            return;
        }

        $this->check(array_values(array_diff($this->checked(), self::KINDS)));
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
