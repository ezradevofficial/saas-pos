<?php

namespace Modules\Accounting\Integrations;

use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Loyalty\Events\CreditCharged;
use Modules\Loyalty\Events\GiftCardSold;
use Modules\Loyalty\Events\LoyaltyLedgerEvent;

/**
 * ACC-28: Loyalty ledger entries as posting sources, one per entry
 * (source type `loyalty.{event}`, source id the entry). The money is
 * `amount`, signed in the event's direction; a sold gift card and a credit
 * charge also carry their channel's field (`pos`, `direct` or `issued`),
 * so the rule can credit or debit what the money came from: a card sold on
 * a POS sale is reclassified from revenue, one sold in the back office
 * comes from cash, one given away is a loyalty cost. Points without a
 * money value (the programme gives a point none) are not a liability:
 * nothing posts.
 */
class LoyaltySources
{
    public function entry(LoyaltyLedgerEvent $event): ?SourceDocument
    {
        if ($event->amountMinor === null || $event->amountMinor === '0' || $event->currency === '') {
            return null;
        }

        $amounts = ['amount' => $event->amountMinor];

        if ($event instanceof GiftCardSold) {
            $amounts[['pos' => 'pos', 'issued' => 'issued'][$event->channel] ?? 'direct'] = $event->amountMinor;
        }

        if ($event instanceof CreditCharged) {
            $amounts[$event->channel === 'pos' ? 'pos' : 'direct'] = $event->amountMinor;
        }

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: $event::sourceType(),
            sourceId: $event->entryId,
            date: CarbonImmutable::parse($event->occurredAt),
            currency: $event->currency,
            amounts: $amounts,
            fx: $event->fx === null || $event->fx['base'] === $event->fx['quote'] ? null : Integrations::fx($event->fx),
            dimensions: array_filter(['branch_id' => $event->branchId, 'party_id' => $event->partyId]),
            reference: $event->reference === null ? null : mb_substr($event->reference, 0, 80),
            link: match ($event->ledger) {
                'gift_card' => ['kind' => 'loyalty.gift_card', 'id' => $event->recordId],
                'credit' => ['kind' => 'loyalty.credit_account', 'id' => $event->recordId],
                default => $event->partyId === null ? null : ['kind' => 'loyalty.customer', 'id' => $event->partyId],
            },
        );
    }
}
