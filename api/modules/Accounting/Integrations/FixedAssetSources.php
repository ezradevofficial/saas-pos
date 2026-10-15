<?php

namespace Modules\Accounting\Integrations;

use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\FixedAssets\Events\FixedAssetPosting;

/**
 * ACC-34 (docs/modules/fixed-assets.md FA-22): a Fixed assets posting as
 * the ledger's source document: its posting id is the source id (so it
 * posts once), in base currency, with the branch, cost centre and party;
 * the company's rule for its source type maps each field (`cost.{category}`,
 * `accumulated.{category}`, `depreciation.{category}`, `proceeds`, `gain`,
 * `loss`, `surplus`, …) to its accounts (ACC-24 maps by category). A run's
 * reversal posts the depreciation rule with its sides swapped.
 */
class FixedAssetSources
{
    public function source(FixedAssetPosting $event): ?SourceDocument
    {
        $dimensions = array_filter([
            'branch_id' => $event->dimensions['branch_id'] ?? null,
            'cost_centre_id' => $event->dimensions['cost_centre_id'] ?? null,
            'party_id' => $event->dimensions['party_id'] ?? null,
        ], fn ($value) => $value !== null);

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: $event->sourceType,
            sourceId: $event->postingId,
            // A calendar day of the company: noon UTC is that day in its time zone (KE, CD).
            date: CarbonImmutable::parse($event->date.' 12:00:00', 'UTC'),
            currency: $event->currency,
            amounts: $event->amounts,
            dimensions: $dimensions,
            reference: $event->reference,
            memo: Integrations::memo($event->reverse ? $event->sourceType.'_reversal' : $event->sourceType, $event->reference),
            link: $event->link,
            reverse: $event->reverse,
        );
    }
}
