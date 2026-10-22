<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/** The default: nothing billed besides the plan. */
class NoAddOnCharges implements AddOnCharges
{
    public function periodLines(Subscription $subscription, string $cycle, CarbonImmutable $start): array
    {
        return [];
    }
}
