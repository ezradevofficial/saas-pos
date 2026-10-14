<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PRC-15, PRC-25: a supplier invoice was approved. The Accounting source
 * contract (ACC-07) for `procurement.supplier_invoice`: `amounts` in base
 * minor digit strings (`net`, `tax`, `total`, `grni`, `price_variance` =
 * net − grni), `fx` the invoice's snapshot, `dimensions` {party_id,
 * branch_id, cost_centre_id?, project_id?}; and `document`, the AP
 * document an Accounting listener creates (decision 3): {invoice_id,
 * number, supplier_reference, party_id, company_id, branch_id,
 * invoice_date, due_date, currency, tax_inclusive, lines: [{description,
 * item_id, qty, unit_price_minor, net_minor, tax_code_id, tax_rate,
 * tax_minor, total_minor, base_net_minor, base_grni_minor, cost_centre_id,
 * project_id, kind: stock|expense}], totals {net, tax, total}, base_totals
 * {net, tax, total}}, money in the invoice currency unless named `base_`.
 */
class SupplierInvoiceApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SOURCE = 'procurement.supplier_invoice';

    /**
     * @param  array<string, string>  $amounts
     * @param  array{rate: string, base: string, quote: string, kind: ?string, effective_at: ?string}  $fx
     * @param  array<string, ?string>  $dimensions
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $invoiceId,
        public readonly string $companyId,
        public readonly string $date,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly array $fx,
        public readonly array $dimensions,
        public readonly string $reference,
        public readonly array $document,
    ) {}
}
