<?php

namespace Modules\Accounting\Ledger;

use App\Core\Http\ApiException;

/**
 * The Accounting module's refusals: 422 with a stable code and a message
 * from `accounting.errors.{code}` that says what happened and what to do.
 */
final class LedgerError
{
    /** @param array<string, string|int> $replace */
    public static function make(string $code, array $replace = [], ?string $field = null, int $status = 422): ApiException
    {
        $message = __("accounting.errors.{$code}", $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
