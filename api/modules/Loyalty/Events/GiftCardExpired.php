<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: What was left on a gift card past its expiry date was written off (breakage).
 */
final class GiftCardExpired extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.gift_card_expired';
    }
}
