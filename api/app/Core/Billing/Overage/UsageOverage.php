<?php

namespace App\Core\Billing\Overage;

use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * LIM-05: usage beyond the plan's quantities, charged at the add-on rate
 * on the next invoice. Implemented by metering (phase 6 Task 3); the
 * default (NoUsageOverage) reports none.
 *
 * Both methods run in the subscribing tenant's context, inside the
 * transaction that issues the renewal invoice.
 */
interface UsageOverage
{
    /**
     * The overage of the period [$from, $to) (the period that just ended),
     * priced against $catalogue (the subscription's locked prices for that
     * period): one line per item over its plan quantity, amounts in minor
     * units of the subscription's currency. Lines already billed are not
     * returned again.
     *
     * @return list<OverageLine>
     */
    public function forPeriod(Subscription $subscription, CatalogueSnapshot $catalogue, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * The renewal invoice $invoiceId (the tenant's BillingInvoice id)
     * carries $lines: mark them billed so they are never charged twice.
     *
     * @param  list<OverageLine>  $lines
     */
    public function billed(Subscription $subscription, string $invoiceId, array $lines): void;
}
