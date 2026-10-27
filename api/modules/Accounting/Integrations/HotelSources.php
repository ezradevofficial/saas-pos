<?php

namespace Modules\Accounting\Integrations;

use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Hotel\Events\HotelPosting;

/**
 * HOT-27 (docs/modules/hotel.md): a Hotel posting as the ledger's source
 * document, idempotent by its posting id (the folio line for charges,
 * payments and deposits, the deposit payment for `hotel.deposit_applied`), in
 * the folio's currency at the line's own rate (CUR-04), with the branch and the
 * guest as customer. The company's rule for the source type maps each field
 * (`ledger`, `revenue.{rooms|extras|fees}`, `tax.{code}`, `tender.{payment
 * method}`, `deposit`); a void or a refund posts negative amounts to the other
 * side of the same rule.
 */
class HotelSources
{
    public function source(HotelPosting $event): ?SourceDocument
    {
        $dimensions = array_filter([
            'branch_id' => $event->dimensions['branch_id'] ?? null,
            'party_id' => $event->dimensions['party_id'] ?? null,
        ], fn ($value) => $value !== null);

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: $event->sourceType,
            sourceId: $event->postingId,
            date: CarbonImmutable::parse($event->at),
            currency: $event->currency,
            amounts: $event->amounts,
            fx: $event->fx === null ? null : Integrations::fx($event->fx),
            dimensions: $dimensions,
            reference: mb_substr($event->reference, 0, 80),
            memo: Integrations::memo($event->sourceType, $event->reference),
            link: $event->link,
        );
    }
}
