<?php

namespace Modules\Procurement\Support;

use App\Core\Http\ApiException;

/**
 * The Procurement module's refusals: a stable code and a message from
 * `procurement.errors.{code}` that says what happened and what to do next.
 */
final class ProcurementError
{
    /** @param array<string, string|int> $replace */
    public static function make(string $code, array $replace = [], ?string $field = null, int $status = 422): ApiException
    {
        $message = __("procurement.errors.{$code}", $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
