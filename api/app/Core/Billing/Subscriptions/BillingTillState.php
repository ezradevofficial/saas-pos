<?php

namespace App\Core\Billing\Subscriptions;

use App\Core\Billing\Models\Subscription;
use App\Core\Sync\Contracts\TillSellingState;
use Carbon\CarbonImmutable;

/**
 * SUB-05: the subscription as the till needs it. Read-only: the POS sells
 * until `pos_stops_at`, then stops (`pos_stopped`); suspended: stopped.
 * Any other status: selling goes on.
 */
class BillingTillState implements TillSellingState
{
    public function forTill(): ?array
    {
        $subscription = Subscription::query()->first(['id', 'status', 'pos_stops_at']);

        if ($subscription === null) {
            return null;
        }

        $stopsAt = $subscription->status === Subscription::READ_ONLY ? $subscription->pos_stops_at : null;

        return [
            'status' => $subscription->status,
            'pos_stops_at' => $stopsAt === null ? null : CarbonImmutable::instance($stopsAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            'pos_stopped' => ! $subscription->posAllowedAt(CarbonImmutable::now()),
        ];
    }
}
