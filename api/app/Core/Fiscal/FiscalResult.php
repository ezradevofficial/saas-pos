<?php

namespace App\Core\Fiscal;

/**
 * What the authority answered: `accepted` with its references, `rejected`
 * (refused; needs a person) or `retry` (no usable answer; try later). The
 * message is safe to show: no credentials, no raw request.
 */
final class FiscalResult
{
    /** @param array<string, scalar|null> $authority */
    private function __construct(
        public readonly string $status,
        public readonly array $authority = [],
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    /** @param array<string, scalar|null> $authority */
    public static function accepted(array $authority): self
    {
        return new self('accepted', $authority);
    }

    public static function rejected(string $code, string $message): self
    {
        return new self('rejected', [], $code, $message);
    }

    public static function retry(string $code, string $message): self
    {
        return new self('retry', [], $code, $message);
    }
}
