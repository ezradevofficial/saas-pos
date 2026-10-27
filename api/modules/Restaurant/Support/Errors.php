<?php

namespace Modules\Restaurant\Support;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Restaurant's API errors (docs/modules/restaurant.md, Errors), worded in `restaurant.errors.*`. */
final class Errors
{
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('restaurant.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }

    /** One transaction on the tenant connection. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }

    /** A transaction-level advisory lock on $key (released at commit or rollback). */
    public static function lock(string $key): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
