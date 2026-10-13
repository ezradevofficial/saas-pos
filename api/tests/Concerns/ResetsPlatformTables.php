<?php

namespace Tests\Concerns;

use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Support\Facades\DB;

/**
 * Platform staff work runs on its own connection (pgsql_platform, ADR 013),
 * outside the test's transaction on `pgsql`, so its writes commit and the
 * public endpoints (runtime role) see them. After each test the schema
 * owner puts the platform and catalogue tables back as they were: staff,
 * tokens and the staff audit log emptied, the catalogue restored from the
 * copy taken before the test. The owner switches the tables' own triggers
 * off for this inside one transaction, so no other session ever sees them off.
 */
trait ResetsPlatformTables
{
    /** Restored in this order (deleted in reverse). */
    private static array $catalogueTables = ['catalogue_modules', 'catalogue_bundles', 'catalogue_prices', 'catalogue_annual_discounts', 'catalogue_coupons'];

    private static array $staffTables = ['platform_audit_logs', 'platform_access_tokens', 'platform_users'];

    protected function setUpResetsPlatformTables(): void
    {
        $ownerConfig = config('database.connections.'.SyncPermissions::OWNER_CONNECTION);
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $saved = [];

        foreach (self::$catalogueTables as $table) {
            $saved[$table] = array_map(fn ($row) => (array) $row, $owner->table($table)->get()->all());
        }

        $this->beforeApplicationDestroyed(function () use ($saved, $ownerConfig) {
            DB::connection('pgsql_platform')->disconnect();
            // A test may have made the owner unusable (WithoutOwnerConnection).
            config(['database.connections.'.SyncPermissions::OWNER_CONNECTION => $ownerConfig]);
            DB::purge(SyncPermissions::OWNER_CONNECTION);
            $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);

            $owner->transaction(function () use ($owner, $saved) {
                $tables = [...self::$staffTables, ...array_reverse(self::$catalogueTables)];

                foreach ($tables as $table) {
                    $owner->statement("alter table {$table} disable trigger user");
                }

                foreach ($tables as $table) {
                    $owner->table($table)->delete();
                }

                foreach (self::$catalogueTables as $table) {
                    foreach (array_chunk($saved[$table], 200) as $rows) {
                        $owner->table($table)->insert($rows);
                    }
                }

                foreach ($tables as $table) {
                    $owner->statement("alter table {$table} enable trigger user");
                }
            });
        });
    }
}
