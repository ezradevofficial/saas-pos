<?php

namespace Modules\Accounting\Integrations;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Manufacturing\Events\ProductionValued;

/**
 * ACC-33 (Manufacturing, MFG-20): a valued Manufacturing document as a
 * posting source, one journal per document (idempotent by its id), in the
 * company's base currency, every line on the debit side with signed
 * amounts (as InventorySources). Each line's value is signed as a stock
 * movement (out negative): stock moves by value less cost variance, its
 * counterpart by −value, the cost variance goes to cost of sales.
 *
 * | Manufacturing kind | Source type                | Counterpart of stock                      |
 * | materials          | manufacturing.materials    | wip (issue: Dr WIP / Cr stock; return reverses) |
 * | production         | manufacturing.production   | wip, less labour and overhead absorbed    |
 * | variance           | manufacturing.variance     | variance against wip (no stock)           |
 * | backflush          | manufacturing.backflush    | cost_of_sales (a sale's ingredients)      |
 *
 * Production also absorbs its labour and overhead: WIP is debited with
 * them and `labour` / `overhead` (the absorbed accounts) credited, so WIP
 * holds materials + labour + overhead − what was received, and the close
 * clears what is left to the variance. Inventory's StockMoved for
 * `manufacturing.*` sources posts nothing (InventorySources): the same
 * movement.
 */
class ManufacturingSources
{
    public const TYPES = [
        ProductionValued::MATERIALS => ['manufacturing.materials', 'wip'],
        ProductionValued::PRODUCTION => ['manufacturing.production', 'wip'],
        ProductionValued::VARIANCE => ['manufacturing.variance', 'wip'],
        ProductionValued::BACKFLUSH => ['manufacturing.backflush', 'cost_of_sales'],
    ];

    public function valued(ProductionValued $event): ?SourceDocument
    {
        [$type, $counter] = self::TYPES[$event->kind] ?? [null, null];

        if ($type === null) {
            return null;
        }

        $stock = BigInteger::zero();
        $counterpart = BigInteger::zero();
        $variance = BigInteger::zero();

        foreach ($event->lines as $line) {
            $value = BigInteger::of((string) $line['value_minor']);
            $lineVariance = BigInteger::of((string) ($line['cost_variance_minor'] ?? '0'));
            $stock = $stock->plus($value->minus($lineVariance));
            $counterpart = $counterpart->minus($value);
            $variance = $variance->plus($lineVariance);
        }

        $labour = BigInteger::of($event->labourMinor);
        $overhead = BigInteger::of($event->overheadMinor);
        $closing = BigInteger::of($event->varianceMinor);
        $counterpart = $counterpart->plus($labour)->plus($overhead)->minus($closing);

        $amounts = array_filter([
            'stock' => (string) $stock,
            $counter => (string) $counterpart,
            'labour' => (string) $labour->negated(),
            'overhead' => (string) $overhead->negated(),
            'variance' => (string) $closing,
            'cost_variance' => (string) $variance,
        ], fn (string $minor) => $minor !== '0');

        if ($amounts === []) {
            return null;
        }

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: $type,
            sourceId: $event->documentId,
            date: CarbonImmutable::parse($event->occurredAt),
            currency: $event->currency,
            amounts: $amounts,
            dimensions: array_filter(['branch_id' => $event->branchId]),
            reference: $event->number === '' ? null : mb_substr($event->number, 0, 80),
            memo: Integrations::memo($type, $event->number),
            link: array_filter(['kind' => $event->documentKind ?? $type, 'id' => $event->documentId, 'order_id' => $event->orderId]),
        );
    }
}
