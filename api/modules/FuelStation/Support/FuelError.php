<?php

namespace Modules\FuelStation\Support;

use App\Core\Http\ApiException;

/** The module's refusals: `fuel.errors.{code}` in both languages, the code in the response. */
final class FuelError
{
    /** @param array<string, string|int> $replace */
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('fuel.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
