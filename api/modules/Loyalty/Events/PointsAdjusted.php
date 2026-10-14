<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Points were adjusted by hand, with a reason.
 */
final class PointsAdjusted extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.points_adjusted';
    }
}
