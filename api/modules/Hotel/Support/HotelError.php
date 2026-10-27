<?php

namespace Modules\Hotel\Support;

use App\Core\Http\ApiException;

/** The module's refusals: `hotel.errors.{code}` in both languages, the code in the response. */
final class HotelError
{
    /** @param array<string, string|int> $replace */
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('hotel.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
