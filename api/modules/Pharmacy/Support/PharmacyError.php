<?php

namespace Modules\Pharmacy\Support;

use App\Core\Http\ApiException;

/** The module's refusals: `pharmacy.errors.{code}` in both languages, the code in the response. */
final class PharmacyError
{
    /** @param array<string, string|int> $replace */
    public static function make(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('pharmacy.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
