<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Points paid for a sale; negative when a void or refund gave them back.
 */
final class PointsRedeemed extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.points_redeemed';
    }
}
