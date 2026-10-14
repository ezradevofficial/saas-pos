<?php

namespace Modules\Stores\Stock;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Stores' API errors (docs/modules/stores.md, Errors), worded in `stores.errors.*`. */
final class Errors
{
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('stores.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }

    /** One transaction on the tenant connection. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
