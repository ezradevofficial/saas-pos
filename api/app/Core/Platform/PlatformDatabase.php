<?php

namespace App\Core\Platform;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * ADR 013: platform staff work runs on its own connection and role
 * (`pgsql_platform`, role app_platform), which reaches only the platform
 * and catalogue tables. The tenant runtime role (`pgsql`) cannot read staff
 * tables and may only read the catalogue (`READ`).
 */
final class PlatformDatabase
{
    public const CONNECTION = 'pgsql_platform';

    /** Public catalogue and quote, and billing snapshots: the runtime role's read-only access. */
    public const READ = 'pgsql';

    public static function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return self::connection()->transaction($callback);
    }
}
