<?php

namespace Modules\POS\Sync;

use RuntimeException;

/**
 * One uploaded record refused (POS-09): a stable code, a translated
 * message saying what happened and what to do, the field at fault, and
 * whether sending it again later can succeed (`retryable`: the server
 * lacks something it may get, such as the sale a void names or a rate).
 * Messages never echo ids from the request.
 */
class Rejection extends RuntimeException
{
    /** @param array<string, string|int> $replace */
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $field = null,
        public readonly bool $retryable = false,
        array $replace = [],
    ) {
        parent::__construct(__("pos.errors.{$errorCode}", $replace));
    }

    /** @return array{code: string, message: string, field: ?string, retryable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'field' => $this->field,
            'retryable' => $this->retryable,
        ];
    }
}
