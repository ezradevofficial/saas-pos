<?php

namespace Modules\Accounting\Integrations;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Stores\Events\StoreConsumptionValued;

/**
 * ACC-31 (Stores, STR-16): an issue or return Inventory valued, as a
 * posting source (`stores.consumption`, one journal per document,
 * idempotent by the document's id), in the company's base currency, with
 * the department and cost centre as dimensions.
 *
 * Like stock journals (InventorySources), every line sits on the debit
 * side with signed amounts: per line, stock moves by its value less its
 * cost variance, `consumption.{department}` (the rule's map takes a
 * department to its expense account, else the default) by the opposite of
 * its value, and `cost_variance` to cost of sales. An issue debits the
 * department's consumption and credits stock; a return the reverse.
 * Inventory's StockMoved for `stores.*` sources posts nothing (the same
 * movement, InventorySources).
 */
class StoresSources
{
    public function consumption(StoreConsumptionValued $event): ?SourceDocument
    {
        $out = $event->direction === StoreConsumptionValued::ISSUE;
        $stock = BigInteger::zero();
        $consumption = BigInteger::zero();
        $variance = BigInteger::zero();

        foreach ($event->lines as $line) {
            // Stores sends positive values; as a movement, an issue's is negative.
            $value = BigInteger::of((string) $line['value_minor'])->abs();
            $value = $out ? $value->negated() : $value;
            $lineVariance = BigInteger::of((string) ($line['cost_variance_minor'] ?? '0'));
            $stock = $stock->plus($value->minus($lineVariance));
            $consumption = $consumption->minus($value);
            $variance = $variance->plus($lineVariance);
        }

        $amounts = array_filter([
            'stock' => (string) $stock,
            "consumption.{$event->departmentId}" => (string) $consumption,
            'cost_variance' => (string) $variance,
        ], fn (string $minor) => $minor !== '0');

        if ($amounts === []) {
            return null;
        }

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: 'stores.consumption',
            sourceId: $event->documentId,
            date: CarbonImmutable::parse($event->occurredAt),
            currency: $event->currency,
            amounts: $amounts,
            dimensions: array_filter(['branch_id' => $event->branchId, 'department_id' => $event->departmentId, 'cost_centre_id' => $event->costCentreId]),
            reference: mb_substr($event->number, 0, 80),
            memo: Integrations::memo('stores.consumption', $event->number),
            link: ['kind' => $out ? 'stores.issue' : 'stores.return', 'id' => $event->documentId],
        );
    }
}
