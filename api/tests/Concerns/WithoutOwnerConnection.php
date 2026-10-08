<?php

namespace Tests\Concerns;

use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Scheduler and worker code must never need the owner's credentials
 * (ADR 002): only migrations and deploy commands do. Call
 * withoutOwnerConnection() after the test's data is built; any later use
 * of the owner connection then fails to connect.
 */
trait WithoutOwnerConnection
{
    protected function withoutOwnerConnection(): void
    {
        DB::purge(SyncPermissions::OWNER_CONNECTION);
        config([
            'database.connections.'.SyncPermissions::OWNER_CONNECTION.'.username' => 'no_such_owner_role',
            'database.connections.'.SyncPermissions::OWNER_CONNECTION.'.password' => 'wrong',
        ]);

        $refused = false;
        try {
            DB::connection(SyncPermissions::OWNER_CONNECTION)->getPdo();
        } catch (PDOException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'The owner connection should be unusable.');

        DB::purge(SyncPermissions::OWNER_CONNECTION);
    }
}
