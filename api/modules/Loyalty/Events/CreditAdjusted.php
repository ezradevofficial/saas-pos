<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: A credit account was adjusted by hand, with a reason (positive: the customer
 * owes more).
 */
final class CreditAdjusted extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.credit_adjusted';
    }
}
