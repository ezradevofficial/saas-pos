<?php

namespace Modules\Procurement\Events;

use Carbon\CarbonImmutable;
use Closure;
use Modules\Procurement\Documents\Invoices;
use Modules\Procurement\Documents\LandedCosts;
use Modules\Procurement\Documents\Receipts;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\Receipt;

/**
 * ACC-29 (core EventReplays, stage 7B closing review): the events
 * Accounting posts, raised again for a period from what Procurement
 * stored, exactly as they were raised live: GoodsReceiptPosted for posted
 * GRNs (by receipt time), LandedCostAllocated for posted landed costs and
 * SupplierInvoiceApproved for approved invoices (by their document date),
 * in the order they happened.
 */
final class ProcurementEventReplay
{
    public function __construct(
        private readonly Receipts $receipts,
        private readonly LandedCosts $landedCosts,
        private readonly Invoices $invoices,
    ) {}

    /** @param Closure(object): void $emit */
    public function __invoke(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        [$fromDay, $toDay] = [$from->toDateString(), $to->toDateString()];
        $events = [];

        foreach (Receipt::query()->where('status', Receipt::POSTED)->whereBetween('received_at', [$from, $to])->orderBy('received_at')->orderBy('id')->get() as $receipt) {
            $events[] = [$receipt->received_at->toIso8601String(), 0, fn () => $this->receipts->postedEvent($receipt)];
        }

        foreach (LandedCost::query()->where('status', LandedCost::POSTED)->whereBetween('cost_date', [$fromDay, $toDay])->orderBy('cost_date')->orderBy('id')->get() as $landedCost) {
            $events[] = [$landedCost->cost_date->toDateString().'T23:59:59', 1, fn () => $this->landedCosts->allocatedEvent($landedCost)];
        }

        foreach (Invoice::query()->where('status', Invoice::APPROVED)->whereBetween('invoice_date', [$fromDay, $toDay])->orderBy('invoice_date')->orderBy('id')->get() as $invoice) {
            $events[] = [$invoice->invoice_date->toDateString().'T23:59:59', 2, fn () => $this->invoices->approvedEvent($invoice)];
        }

        usort($events, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($events as [, , $make]) {
            $emit($make());
        }
    }
}
