<?php

namespace App\Core\Payments;

/**
 * What a provider adapter answered (PaymentProvider). `data` is the
 * minimum worth keeping (result code and text, amount, receipt, dates),
 * never names or phone numbers. `amountMinor` is the amount the provider
 * reports, when it reports one (checked against the intent).
 */
final class ProviderResult
{
    /** @param array<string, scalar|null> $data */
    public function __construct(
        public readonly string $status,
        public readonly ?string $requestId = null,
        public readonly ?string $checkoutId = null,
        public readonly ?string $receipt = null,
        public readonly ?string $resultCode = null,
        public readonly ?string $message = null,
        public readonly ?int $amountMinor = null,
        public readonly array $data = [],
    ) {}

    public static function succeeded(?string $receipt = null, array $data = [], ?int $amountMinor = null): self
    {
        return new self('succeeded', receipt: $receipt, amountMinor: $amountMinor, data: $data);
    }

    public static function pending(?string $requestId = null, ?string $checkoutId = null, array $data = []): self
    {
        return new self('pending', $requestId, $checkoutId, data: $data);
    }

    public static function failed(?string $resultCode, ?string $message, array $data = []): self
    {
        return new self('failed', resultCode: $resultCode, message: $message, data: $data);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
