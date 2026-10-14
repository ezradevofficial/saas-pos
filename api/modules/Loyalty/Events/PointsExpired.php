<?php

namespace Modules\Loyalty\Events;

/**
 * Stage 7B: Points expired (first in, first out).
 */
final class PointsExpired extends LoyaltyLedgerEvent
{
    public static function sourceType(): string
    {
        return 'loyalty.points_expired';
    }
}
