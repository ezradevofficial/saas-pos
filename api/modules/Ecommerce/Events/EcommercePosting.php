<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-32: a document for the ledger, raised after it commits: a delivered
 * order (`ecommerce.order`), a return of a delivered order
 * (`ecommerce.return`) or a rider cash-up (`ecommerce.cod_cashup`).
 * Accounting posts it through the company's posting rules, idempotently by
 * the posting id, while Accounting is active. Amounts are signed minor
 * units of `currency` by field; Σ debits = Σ credits under the default
 * rules (docs/modules/ecommerce.md).
 */
class EcommercePosting implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ORDER = 'ecommerce.order';

    public const RETURN = 'ecommerce.return';

    public const CASHUP = 'ecommerce.cod_cashup';

    /**
     * @param  array<string, string>  $amounts
     * @param  array<string, mixed>|null  $fx
     * @param  array<string, ?string>  $dimensions
     * @param  array{kind: string, id: string}  $link
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $sourceType,
        public readonly string $postingId,
        public readonly string $at,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly ?array $fx,
        public readonly array $dimensions,
        public readonly string $reference,
        public readonly array $link,
    ) {}
}
