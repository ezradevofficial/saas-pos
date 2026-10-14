<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Money was received from a customer on their credit account.
 */
final class CreditPaymentReceived extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.credit_payment_received';
    }
}
