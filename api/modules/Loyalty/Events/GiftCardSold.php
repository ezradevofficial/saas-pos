<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: A gift card was loaded: sold at the till or in the back office, or issued as given;
 * negative when the sale that sold it was voided.
 */
final class GiftCardSold extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.gift_card_sold';
    }
}
