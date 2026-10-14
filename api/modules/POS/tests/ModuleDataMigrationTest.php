<?php

namespace Modules\POS\Tests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// LOY-27, M1 (docs/modules/loyalty.md, "POS schema change", approved by the
// owner on 2026-10-14): the module-data columns are added without a long
// lock: a lock_timeout, CHECK constraints added NOT VALID, then validated
// by a separate migration outside a transaction.
class ModuleDataMigrationTest extends TestCase
{
    use RefreshTenantDatabase;

    private const DIR = __DIR__.'/../database/migrations/';

    public function test_the_columns_are_added_with_a_lock_timeout_and_checks_not_valid(): void
    {
        $migration = require self::DIR.'2026_11_05_000100_add_module_data_to_pos_tables.php';
        $source = (string) file_get_contents(self::DIR.'2026_11_05_000100_add_module_data_to_pos_tables.php');

        $this->assertStringContainsString("set local lock_timeout = '5s'", $source);
        $this->assertStringContainsString(') not valid', $source);
        $this->assertCount(4, $migration::CHECKS);
    }

    public function test_the_checks_are_validated_by_a_migration_outside_a_transaction(): void
    {
        $validate = require self::DIR.'2026_11_05_000110_validate_module_data_checks.php';
        $this->assertFalse($validate->withinTransaction);

        $migration = require self::DIR.'2026_11_05_000100_add_module_data_to_pos_tables.php';
        $names = array_column(array_values($migration::CHECKS), 0);
        $rows = DB::connection(TenantContext::CONNECTION)->select('select conname, convalidated from pg_constraint where conname in ('.implode(',', array_fill(0, count($names), '?')).')', $names);

        $this->assertEqualsCanonicalizing($names, array_column($rows, 'conname'));
        $this->assertSame([true, true, true, true], array_map(fn ($row) => (bool) $row->convalidated, $rows));
    }
}
