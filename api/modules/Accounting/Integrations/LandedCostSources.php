<?php

namespace Modules\Accounting\Integrations;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\PostingResult;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\LandedCostAllocated;

/**
 * ACC-32, PRC-17 (stage 7B closing review; procurement.md decision 19):
 * landed costs meet in the landed cost clearing account (`landed_cost`).
 *
 * - The charges' bills credit the supplier and debit clearing: each
 *   supplier's bill named on a posted landed cost document (its charges
 *   grouped by supplier, bill reference and currency) becomes a posted
 *   Accounting supplier invoice through `procurement.landed_cost_bill`
 *   (Dr `landed_cost` / Cr `total` with the party, in the charge currency
 *   at the charge's rate), open for payment like any bill.
 * - What the charges were allocated to credits clearing: stock Inventory
 *   tracked through its stock journals (`inventory.receipt`'s
 *   `landed_cost` for allocations before the receipt posted,
 *   `inventory.landed_cost` after); everything else (services, expense
 *   lines and stock Inventory did not track) here, through
 *   `procurement.landed_cost`: Dr `expense` / Cr `landed_cost`, from
 *   LandedCostAllocated for allocations to GRNs already posted and from
 *   GoodsReceiptPosted (`untracked_landed`) for those a GRN took when it
 *   posted. So the clearing account nets to zero once every bill posts.
 *
 * Idempotent per source like every posting (the bill's source id is its
 * first charge's id; the expense's the landed cost's or the GRN's id).
 */
class LandedCostSources
{
    public const SOURCE = 'procurement.landed_cost';

    public const BILL = 'procurement.landed_cost_bill';

    public function __construct(
        private readonly PostingService $posting,
        private readonly SupplierInvoices $documents,
    ) {}

    /** A posted landed cost: its untracked part to expense, then each supplier's bill. */
    public function allocated(LandedCostAllocated $event): ?PostingResult
    {
        $result = $this->expense($event->companyId, $event->landedCostId, $event->date, $event->currency, (string) ($event->amounts['expense'] ?? '0'),
            ['branch_id' => $event->branchId], $event->reference, ['kind' => 'procurement.landed_cost', 'id' => $event->landedCostId]);

        foreach ($this->bills($event) as $bill) {
            $result = $this->documents->post($bill, null);
        }

        return $result;
    }

    /** A posted GRN: the landed cost it took on lines no stock journal carries, to expense. */
    public function received(GoodsReceiptPosted $event): ?PostingResult
    {
        return $this->expense($event->companyId, $event->receiptId, $event->date, $event->currency, (string) ($event->amounts['untracked_landed'] ?? '0'),
            array_filter(['branch_id' => $event->dimensions['branch_id'] ?? null, 'cost_centre_id' => $event->dimensions['cost_centre_id'] ?? null,
                'project_id' => $event->dimensions['project_id'] ?? null]), $event->reference, ['kind' => 'procurement.receipt', 'id' => $event->receiptId]);
    }

    /** @param array<string, string> $dimensions */
    private function expense(string $companyId, string $sourceId, string $date, string $currency, string $amount, array $dimensions, string $reference, array $link): ?PostingResult
    {
        $minor = BigInteger::of($amount);

        if ($minor->isZero()) {
            return null;
        }

        return $this->posting->post(new SourceDocument(
            companyId: $companyId,
            sourceType: self::SOURCE,
            sourceId: $sourceId,
            date: CarbonImmutable::parse($date),
            currency: $currency,
            amounts: ['expense' => (string) $minor, 'landed_cost' => (string) $minor->negated()],
            dimensions: $dimensions,
            reference: mb_substr($reference, 0, 80),
            link: $link,
        ));
    }

    /**
     * The supplier bills of a landed cost: its charges grouped by supplier,
     * bill reference and currency (charges without a supplier, from before
     * decision 19, have no bill here: theirs is entered in Accounting).
     *
     * @return list<SourceDocument>
     */
    private function bills(LandedCostAllocated $event): array
    {
        $groups = [];

        foreach ($event->charges as $charge) {
            if (empty($charge['party_id']) || trim((string) ($charge['bill_reference'] ?? '')) === '' || ! isset($charge['base_amount_minor'])) {
                continue;
            }

            $groups[$charge['party_id']."\n".mb_strtolower(trim((string) $charge['bill_reference']))."\n".$charge['currency']][] = $charge;
        }

        $bills = [];

        foreach ($groups as $charges) {
            $first = $charges[0];
            $amount = array_reduce($charges, fn (BigInteger $sum, array $c) => $sum->plus((string) $c['amount_minor']), BigInteger::zero());
            $base = array_reduce($charges, fn (BigInteger $sum, array $c) => $sum->plus((string) $c['base_amount_minor']), BigInteger::zero());
            $fx = $first['fx'] ?? ['rate' => '1', 'base' => $event->currency, 'quote' => $event->currency, 'kind' => null, 'effective_at' => null];
            $document = [
                'number' => $event->reference,
                'memo_key' => 'accounting.integrations.landed_cost_bill',
                'supplier_reference' => mb_substr(trim((string) $first['bill_reference']), 0, 100),
                'party_id' => $first['party_id'],
                'company_id' => $event->companyId,
                'branch_id' => $event->branchId,
                'invoice_date' => $event->date,
                'due_date' => $event->date,
                'currency' => $first['currency'],
                'tax_inclusive' => false,
                'fx' => $fx,
                'lines' => array_map(fn (array $c) => [
                    'description' => (string) $c['description'], 'item_id' => null, 'qty' => '1', 'unit_price_minor' => (string) $c['amount_minor'],
                    'net_minor' => (string) $c['amount_minor'], 'tax_code_id' => null, 'tax_rate' => null, 'tax_minor' => '0', 'total_minor' => (string) $c['amount_minor'],
                    'cost_centre_id' => null, 'project_id' => null, 'field' => 'landed_cost',
                ], $charges),
                'totals' => ['net' => (string) $amount, 'tax' => '0', 'total' => (string) $amount],
                'base_totals' => ['net' => (string) $base, 'tax' => '0', 'total' => (string) $base],
            ];

            $bills[] = new SourceDocument(
                companyId: $event->companyId,
                sourceType: self::BILL,
                sourceId: (string) $first['id'],
                date: CarbonImmutable::parse($event->date),
                currency: $event->currency,
                amounts: ['landed_cost' => (string) $base, 'total' => (string) $base],
                dimensions: ['party_id' => $first['party_id'], 'branch_id' => $event->branchId],
                reference: mb_substr($event->reference.' / '.trim((string) $first['bill_reference']), 0, 80),
                link: ['kind' => 'procurement.landed_cost', 'id' => $event->landedCostId, 'document' => $document],
                foreign: $first['currency'] === $event->currency ? [] : ['total' => [
                    'currency' => $first['currency'], 'amount_minor' => (string) $amount, 'base_minor' => (string) $base, 'fx' => $fx,
                ]],
            );
        }

        return $bills;
    }
}
