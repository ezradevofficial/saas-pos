<?php

namespace App\Core\Billing\Overage;

use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/** LIM-05 until metering (Task 3) is bound: no overage. */
final class NoUsageOverage implements UsageOverage
{
    public function forPeriod(Subscription $subscription, CatalogueSnapshot $catalogue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function billed(Subscription $subscription, string $invoiceId, array $lines): void {}
}
