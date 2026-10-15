<?php

namespace Modules\Inventory\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-04 (stage 7B closing review): movement kinds are rows of
// `inventory_movement_kinds`, the ledger's `kind` a foreign key to it. A
// fresh migrate seeds Inventory's kinds and each module's migration adds
// its own; a database migrated before (with the old kind check) is moved
// to the lookup keeping every kind the check allowed. Loyalty's gift card
// kind migration rebuilds its check from what it allows, never from a
// typed list. Upgrades run as the schema owner in a transaction that is
// never committed.
class MovementKindsMigrationTest extends TestCase
{
    use RefreshTenantDatabase;

    private const OWNER = 'pgsql_owner';

    private const ALL = ['adjustment_in', 'adjustment_out', 'cost_adjustment', 'count_gain', 'count_loss', 'opening', 'opening_reversal', 'receipt',
        'sale', 'sale_return', 'sale_void', 'store_issue', 'store_return', 'transfer_in', 'transfer_loss', 'transfer_out'];

    private function kinds(): array
    {
        return DB::connection(self::OWNER)->table('inventory_movement_kinds')->orderBy('kind')->pluck('kind')->all();
    }

    private function constraint(string $table, string $name): ?string
    {
        $definition = DB::connection(self::OWNER)->scalar('select pg_get_constraintdef(oid) from pg_constraint where conrelid = ?::regclass and conname = ?', [$table, $name]);

        return $definition === null ? null : (string) $definition;
    }

    private function hasKindForeignKey(): bool
    {
        return DB::connection(self::OWNER)->scalar("select count(*) from pg_constraint where conrelid = 'inventory_movements'::regclass and contype = 'f' and confrelid = 'inventory_movement_kinds'::regclass") > 0;
    }

    /** Run $fn as the schema owner, in a transaction rolled back afterwards. */
    private function asOwner(callable $fn): void
    {
        $previous = DB::getDefaultConnection();
        $owner = DB::connection(self::OWNER);
        $owner->beginTransaction();
        DB::setDefaultConnection(self::OWNER);

        try {
            $fn($owner);
        } finally {
            DB::setDefaultConnection($previous);
            $owner->rollBack();
        }
    }

    public function test_a_fresh_migrate_has_the_lookup_with_every_modules_kinds_and_no_check(): void
    {
        $this->assertSame(self::ALL, $this->kinds());
        $this->assertTrue($this->hasKindForeignKey());
        $this->assertNull($this->constraint('inventory_movements', 'inventory_movements_kind_check'));

        // The runtime role only reads the lookup.
        $runtime = config('database.connections.pgsql.username');
        $this->assertTrue((bool) DB::selectOne('select has_table_privilege(?, ?, ?) as can', [$runtime, 'public.inventory_movement_kinds', 'SELECT'])->can);
        $this->assertFalse((bool) DB::selectOne('select has_table_privilege(?, ?, ?) as can', [$runtime, 'public.inventory_movement_kinds', 'INSERT'])->can);

        // A module's migration rolls back only its own rows.
        $this->asOwner(function () {
            /** @var Migration $stores */
            $stores = require base_path('modules/Inventory/database/migrations/2026_11_10_000200_add_store_movement_kinds.php');
            $stores->down();
            $this->assertSame(array_values(array_diff(self::ALL, ['store_issue', 'store_return'])), $this->kinds());
            $stores->up();
            $this->assertSame(self::ALL, $this->kinds());
        });
    }

    public function test_a_database_with_the_old_kind_check_is_moved_to_the_lookup(): void
    {
        $this->asOwner(function ($owner) {
            // The schema as it was: no lookup, a check listing every kind (Inventory's, Stores', cost adjustments).
            $owner->statement('alter table inventory_movements drop constraint '.$owner->scalar("select conname from pg_constraint where conrelid = 'inventory_movements'::regclass and contype = 'f' and confrelid = 'inventory_movement_kinds'::regclass"));
            Schema::drop('inventory_movement_kinds');
            $owner->statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('".implode("', '", self::ALL)."'))");

            /** @var Migration $migration */
            $migration = require base_path('modules/Inventory/database/migrations/2026_11_12_000200_move_movement_kinds_into_a_lookup.php');
            $migration->up();

            $this->assertSame(self::ALL, $this->kinds());
            $this->assertTrue($this->hasKindForeignKey());
            $this->assertNull($this->constraint('inventory_movements', 'inventory_movements_kind_check'));

            // Run again (a fresh database runs it too): nothing changes.
            $migration->up();
            $this->assertSame(self::ALL, $this->kinds());
        });
    }

    public function test_loyaltys_gift_card_kind_migration_keeps_kinds_it_did_not_add(): void
    {
        $this->asOwner(function ($owner) {
            // A later kind added by another migration.
            $owner->statement('alter table loyalty_gift_card_entries drop constraint loyalty_gift_card_entries_kind_check');
            $owner->statement("alter table loyalty_gift_card_entries add constraint loyalty_gift_card_entries_kind_check check (kind in ('load', 'redeem', 'reverse_redeem', 'refund_credit', 'adjust', 'expire', 'transfer') and amount_minor <> 0)");

            /** @var Migration $migration */
            $migration = require base_path('modules/Loyalty/database/migrations/2026_11_07_000200_add_expiry_to_loyalty_gift_card_entries.php');
            $migration->down();
            $definition = (string) $this->constraint('loyalty_gift_card_entries', 'loyalty_gift_card_entries_kind_check');
            $this->assertStringNotContainsString("'expire'", $definition);
            $this->assertStringContainsString("'transfer'", $definition);
            $this->assertStringContainsString('amount_minor <> 0', $definition);

            $migration->up();
            $definition = (string) $this->constraint('loyalty_gift_card_entries', 'loyalty_gift_card_entries_kind_check');
            $this->assertStringContainsString("'expire'", $definition);
            $this->assertStringContainsString("'transfer'", $definition);
        });
    }
}
