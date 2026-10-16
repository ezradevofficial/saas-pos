<?php

namespace Modules\Ecommerce\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** One transaction on the tenant connection (a record and its audit commit together, AUD-01). */
final class Tx
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }

    /** A transaction-scoped advisory lock on $key (released at commit or rollback). */
    public static function lock(string $key): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
