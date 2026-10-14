<?php

namespace Modules\Loyalty\Support;

use App\Core\Http\ApiException;

/** A refusal with a stable code and a translated message (`loyalty.errors.{code}`) that says what to do. */
final class LoyaltyError
{
    /** @param array<string, string|int> $replace */
    public static function make(string $code, ?string $field = null, array $replace = [], int $status = 422): ApiException
    {
        $message = __("loyalty.errors.{$code}", $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
