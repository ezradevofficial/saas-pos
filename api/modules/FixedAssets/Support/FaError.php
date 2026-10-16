<?php

namespace Modules\FixedAssets\Support;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Fixed assets' API errors (docs/modules/fixed-assets.md, Errors), worded in `fixed_assets.errors.*`. */
final class FaError
{
    public static function make(string $code, array $replace = [], ?string $field = null, int $status = 422): ApiException
    {
        $message = __('fixed_assets.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }

    /** One transaction on the tenant connection. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
