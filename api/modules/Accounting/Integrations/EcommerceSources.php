<?php

namespace Modules\Accounting\Integrations;

use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Ecommerce\Events\EcommercePosting;

/**
 * ECOM-32 (docs/modules/ecommerce.md): an E-commerce posting as the
 * ledger's source document, idempotent by its posting id (the order for
 * `ecommerce.order` and `ecommerce.return`, the cash-up for
 * `ecommerce.cod_cashup`), in the document's currency at its own rate
 * (CUR-04), with the branch and the customer. The company's rule for the
 * source type maps each field (`tender.{payment method}`, `cod`,
 * `revenue.{category}`, `promotion`, `delivery`, `tax.{code}`; `cash`,
 * `shortage`, `overage`); a return without a rule of its own posts the
 * order rule with its sides swapped (`accounting.rule_fallbacks`).
 */
class EcommerceSources
{
    public function source(EcommercePosting $event): ?SourceDocument
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
