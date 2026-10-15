<?php

namespace Modules\Manufacturing\Support;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Manufacturing's API errors (docs/modules/manufacturing.md, Errors), worded in `manufacturing.errors.*`. */
final class Errors
{
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('manufacturing.errors.'.$code, $replace);

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
