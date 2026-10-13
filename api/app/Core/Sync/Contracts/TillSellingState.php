<?php

namespace App\Core\Sync\Contracts;

/**
 * SUB-05, NFR-04: whether the till may keep selling, sent with the
 * bootstrap and every pull so a till that is offline when selling stops
 * still knows when it does (`pos_stops_at`), and a later sync tells it
 * that selling may resume. Billing binds it; without billing, null.
 */
interface TillSellingState
{
    /** @return array{status: ?string, pos_stops_at: ?string, pos_stopped: bool}|null */
    public function forTill(): ?array;
}
