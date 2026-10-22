<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * MKT-03 (ADR 024): charges billed with the subscription that are not
 * catalogue items, such as paid marketplace apps. Each period invoice
 * carries their lines next to the plan's. Called in the customer's
 * context; amounts in the subscription's currency, minor units.
 */
interface AddOnCharges
{
    /**
     * Invoice lines for the period of $cycle (`monthly` or `annual`)
     * starting on $start: each `{type, key, name, quantity, unit_amount,
     * amount, months}` with a positive amount.
     *
     * @return list<array<string, mixed>>
     */
    public function periodLines(Subscription $subscription, string $cycle, CarbonImmutable $start): array;
}
