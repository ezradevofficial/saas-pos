<?php

namespace Modules\Ecommerce\Support;

use App\Core\Http\ApiException;

/**
 * The module's refusals: a stable code and a message from
 * `ecommerce.errors.{code}` that says what happened and what to do next.
 */
final class EcomError
{
    /**
     * @param  array<string, string|int>  $replace
     * @param  array<string, mixed>  $extra
     */
    public static function make(string $code, array $replace = [], ?string $field = null, int $status = 422, array $extra = []): ApiException
    {
        $message = __("ecommerce.errors.{$code}", $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]], extra: $extra);
    }
}
