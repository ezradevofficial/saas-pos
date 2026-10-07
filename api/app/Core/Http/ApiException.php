<?php

namespace App\Core\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A domain error for the API envelope `{message, code, errors?}`. The
 * message is already translated; $extra adds top-level keys (for example a
 * challenge id).
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = [],
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers);
    }
}
