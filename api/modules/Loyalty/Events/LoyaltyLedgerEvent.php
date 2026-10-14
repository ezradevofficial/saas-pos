<?php

namespace Modules\Loyalty\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stage 7B (Accounting integrations): one entry of a Loyalty ledger
 * (points, gift card or credit), as the module tells other modules about
 * it, so Accounting never reads Loyalty tables. One event per ledger
 * entry, dispatched after commit; `entryId` is unique and stable, so a
 * listener can post it once.
 *
 * Amounts are signed in the event's own direction (a positive
 * GiftCardRedeemed is money taken off a card, a negative one money put
 * back), as minor-unit digit strings of `currency`:
 *
 * - `amountMinor`: the money of the entry (gift cards and credit) or, for
 *   points, the points' money value when the programme gives a point a
 *   value (`points × point_value_minor` in the programme currency), else
 *   null: such points are not money and carry no liability.
 * - `points`: the points (points events only).
 * - `channel`: where it happened: `pos` (a POS sale, void or refund),
 *   `back_office` (sold, charged, paid or adjusted by a person), `issued`
 *   (a card given, not sold) or `system` (expiry).
 * - `recordId`: the gift card or the account the entry belongs to (the
 *   back office's drill-down target).
 * - `fx`: the sale's own rate snapshot when the entry is in the sale's
 *   currency (CUR-04), else null (the core rate on the day applies).
 */
abstract class LoyaltyLedgerEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array{rate: string, base: string, quote: string, kind?: ?string, effective_at?: ?string}|null  $fx
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $ledger,
        public readonly string $entryId,
        public readonly string $entryKind,
        public readonly string $recordId,
        public readonly ?string $partyId,
        public readonly ?string $branchId,
        public readonly ?string $saleId,
        public readonly string $channel,
        public readonly string $occurredAt,
        public readonly string $currency,
        public readonly ?string $amountMinor,
        public readonly ?string $points = null,
        public readonly ?array $fx = null,
        public readonly ?string $reference = null,
    ) {}

    /** The Accounting source type of this event (`loyalty.{name}`). */
    abstract public static function sourceType(): string;
}
