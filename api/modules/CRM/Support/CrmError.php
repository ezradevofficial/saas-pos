<?php

namespace Modules\CRM\Support;

use App\Core\Http\ApiException;

/**
 * The CRM module's refusals: a stable code and a message from
 * `crm.errors.{code}` that says what happened and what to do next.
 */
final class CrmError
{
    /** @param array<string, string|int> $replace */
    public static function make(string $code, array $replace = [], ?string $field = null, int $status = 422): ApiException
    {
        $message = __("crm.errors.{$code}", $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
