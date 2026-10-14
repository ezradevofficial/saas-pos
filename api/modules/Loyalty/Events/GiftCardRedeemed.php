<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Money was taken off a gift card as a tender; negative when it was put back
 * (a void or a refund).
 */
final class GiftCardRedeemed extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.gift_card_redeemed';
    }
}
