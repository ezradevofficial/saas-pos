<?php

namespace Modules\HR\Support;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** HR's API errors (docs/modules/hr.md, Errors), worded in `hr.errors.*`. */
final class Errors
{
    public static function make(int $status, string $code, array $replace = [], ?string $field = null, array $extra = []): ApiException
    {
        $message = __('hr.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]], extra: $extra);
    }

    /** One transaction on the tenant connection. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
