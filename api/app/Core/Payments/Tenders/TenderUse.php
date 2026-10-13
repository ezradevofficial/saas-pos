<?php

namespace App\Core\Payments\Tenders;

/**
 * One payment a tender provider is asked about (TenderProvider::check):
 * where and for whom, the money in the payment's currency and in the
 * document's, and the module's `tender` object as the till sent it.
 */
final class TenderUse
{
    public const SALE = 'sale';

    public const REFUND = 'refund';

    /** @param array<string, mixed> $tender */
    public function __construct(
        public readonly string $kind,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly ?string $customerId,
        public readonly ?string $userId,
        public readonly string $currency,
        public readonly string $amountMinor,
        public readonly string $documentCurrency,
        public readonly string $amountInDocumentMinor,
        public readonly array $tender,
    ) {}
}
