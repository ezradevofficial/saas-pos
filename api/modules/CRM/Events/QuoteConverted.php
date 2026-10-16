<?php

namespace Modules\CRM\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * CRM-24, CRM-34: an accepted quote is converted to `target` (`invoice`
 * today; `pos_order` and `ecommerce_order` later). Dispatched inside the
 * converting transaction, never after commit: the target module's handler
 * (Accounting's for `invoice`) writes its document in the same
 * transaction and sets `result` (`{type, id, label}`), or throws to refuse
 * (the conversion rolls back). When no handler sets a result, the quote
 * is not converted.
 *
 * Lines: `[{line_no, item_id, description, uom_id, qty, unit_price_minor,
 * discount_percent, gross_minor, discount_minor, amount_minor,
 * tax_code_id, tax_minor, total_minor}]`, amounts as minor digit strings
 * in `currency`, quantities decimal strings; `totals`: `{subtotal,
 * discount, tax, total}` the same way. `date` is the conversion day in the
 * company's time zone (Y-m-d).
 */
class QuoteConverted
{
    use Dispatchable;

    /** @var array{type: string, id: string, label: ?string}|null */
    public ?array $result = null;

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $totals
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $quoteId,
        public readonly string $number,
        public readonly string $target,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $partyId,
        public readonly string $currency,
        public readonly bool $taxInclusive,
        public readonly string $quoteDate,
        public readonly string $date,
        public readonly string $userId,
        public readonly array $lines,
        public readonly array $totals,
    ) {}
}
