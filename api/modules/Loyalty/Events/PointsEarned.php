<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Points were earned on a sale; negative when a void or refund reversed them.
 */
final class PointsEarned extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.points_earned';
    }
}
