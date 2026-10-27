<?php

namespace Modules\Salon\Support;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Salon API errors (docs/modules/salon.md), worded in `salon.errors.*`. */
final class Errors
{
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('salon.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }

    /** One transaction on the tenant connection. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }

    /** Runs $fn; a name already taken at the place (a unique index) is a 422 on `name`. */
    public static function named(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException) {
            throw self::make(422, 'name_taken', field: 'name');
        }
    }

    /** A transaction-level advisory lock on $key (released at commit or rollback). */
    public static function lock(string $key): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
