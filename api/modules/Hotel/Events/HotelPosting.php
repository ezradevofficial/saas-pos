<?php

namespace Modules\Hotel\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOT-27: a document for the ledger, raised after it commits: a room, extra
 * or fee charge (`hotel.charge`, negative for a void), a payment taken or
 * refunded (`hotel.payment`, negative for a refund), a deposit
 * (`hotel.deposit`) or a deposit applied against the guest ledger
 * (`hotel.deposit_applied`). Accounting posts it through the company's
 * posting rules, idempotently by the posting id, while Accounting is
 * active. Amounts are signed minor units of `currency` by field; Σ debits =
 * Σ credits under the default rules (docs/modules/hotel.md).
 */
class HotelPosting implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const CHARGE = 'hotel.charge';

    public const PAYMENT = 'hotel.payment';

    public const DEPOSIT = 'hotel.deposit';

    public const DEPOSIT_APPLIED = 'hotel.deposit_applied';

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
