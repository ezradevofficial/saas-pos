<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: A sale was charged to a customer's credit account; negative when a void or
 * refund reversed it.
 */
final class CreditCharged extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.credit_charged';
    }
}
