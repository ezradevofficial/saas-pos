<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PRC-14, PRC-25: a GRN was posted. The Accounting source contract
 * (ACC-07) for `procurement.goods_receipt` (docs/modules/procurement.md,
 * Events): `amounts` in base minor units as digit strings (`stock_value`,
 * `expense`, `grni`, `landed_cost`), with stock_value + expense = grni +
 * landed_cost, and `untracked_landed` (the landed cost on lines no stock
 * journal carries: services and untracked stock); `fx` the receipt's rate snapshot (CUR-04); `dimensions`
 * {branch_id, party_id, cost_centre_id?, project_id?}; `lines` the accepted
 * lines (docs/modules/fixed-assets.md FA-21).
 */
class GoodsReceiptPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SOURCE = 'procurement.goods_receipt';

    /**
     * @param  array<string, string>  $amounts
     * @param  array{rate: string, base: string, quote: string, kind: ?string, effective_at: ?string}  $fx
     * @param  array<string, ?string>  $dimensions
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $date,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly array $fx,
        public readonly array $dimensions,
        public readonly string $reference,
        public readonly string $orderId,
        /** Fixed assets (FA-21): the accepted lines {line_id, order_line_id, item_id, description, qty, base_value_minor, landed_minor, serials, location_id}. */
        public readonly array $lines = [],
    ) {}
}
