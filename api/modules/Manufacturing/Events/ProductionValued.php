<?php

namespace Modules\Manufacturing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * MFG-20, ACC-33: a Manufacturing document's value, once, for Accounting:
 *
 * - `materials`: an issue (lines out, negative) or a return (back,
 *   positive) Inventory valued;
 * - `production`: a report's good output and by-products received into
 *   stock (positive) with the labour and overhead it absorbed;
 * - `variance`: an order closed with work in progress left;
 * - `backflush`: a sale's ingredients consumed (negative) or given back
 *   (positive).
 *
 * Each line carries its value as a stock movement (signed, minor units of
 * `currency`, the company's base currency) and Inventory's cost variance
 * (what a later receipt corrects of a shortfall's cost), so stock moves by
 * value − variance, the counterpart by −value and the variance goes to
 * cost of sales. Decision (MFG-20): Accounting posts Manufacturing's
 * sources from this event and skips Inventory's `StockMoved` for them.
 */
class ProductionValued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const MATERIALS = 'materials';

    public const PRODUCTION = 'production';

    public const VARIANCE = 'variance';

    public const BACKFLUSH = 'backflush';

    /**
     * @param  list<array{line_id: string, item_id: string, value_minor: string, cost_variance_minor: string}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $kind,
        public readonly string $documentId,
        public readonly string $number,
        public readonly ?string $orderId,
        public readonly ?string $orderNumber,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $occurredAt,
        public readonly string $currency,
        public readonly array $lines,
        public readonly string $labourMinor = '0',
        public readonly string $overheadMinor = '0',
        public readonly string $varianceMinor = '0',
        // The document's own kind for links (`manufacturing.issue`, `.return`, `.report`, `.order`, `.backflush`).
        public readonly ?string $documentKind = null,
    ) {}
}
