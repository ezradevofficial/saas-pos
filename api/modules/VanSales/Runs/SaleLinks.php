<?php

namespace Modules\VanSales\Runs;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\POS\Events\SaleStockLines;
use Modules\VanSales\Credit\CreditCheck;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\StockLine;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Reconciliation\Reopening;
use Modules\VanSales\Support\Qty;
use Modules\VanSales\Support\Settings;
use Modules\VanSales\Support\Units;

/**
 * VAN-10, VAN-11, VAN-13, VAN-16, VAN-20: what a POS sale, its void and its
 * refund mean for a van's run. Read from the events' snapshots (no POS table
 * is read, except the POS stock-lines contract for a refund's units). Every
 * write is once per source (unique indexes), so a retried listener adds
 * nothing. The sale itself is never judged out: it is flagged (VAN-11).
 *
 * Any sale at a van's location counts for that van, whatever the device
 * says: the run the sale names is used only if it is the van's own; else the
 * van's open run, else the van's latest run before the sale, flagged
 * `unlinked`. A sale reaching a run that was already counted is flagged
 * `after_count` and reopens its reconciliation (Reopening). Credit is
 * ledgered and judged against the limit in every case.
 */
class SaleLinks
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Ledger $ledger,
        private readonly Units $units,
        private readonly Auditor $auditor,
        private readonly Reopening $reopening,
    ) {}

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function completed(string $saleId, string $locationId, array $sale): void
    {
        if (VanSale::query()->where('sale_id', $saleId)->exists()) {
            return;
        }

        $van = Van::query()->where('location_id', $locationId)->first();

        // A sale at a place that is no van's is a normal sale and nothing more.
        if ($van === null) {
            return;
        }

        $ext = $sale['extensions']['van_sales'] ?? null;
        $soldAt = CarbonImmutable::parse($sale['sold_at'])->utc();
        $flags = [];
        $run = null;

        if (is_array($ext) && ! empty($ext['run_id']) && Str::isUuid((string) $ext['run_id'])) {
            $named = Run::query()->whereKey($ext['run_id'])->first();
            $run = $named !== null && $named->van_id === $van->id ? $named : null;
        }

        if ($run === null) {
            $flags[] = 'unlinked';
            $run = Run::query()->where('van_id', $van->id)->where('status', 'open')->first()
                ?? Run::query()->where('van_id', $van->id)->where('started_at', '<=', $soldAt)->orderByDesc('started_at')->first();
        }

        if ($run !== null && $run->status !== 'open') {
            $flags[] = 'after_count';
        }

        $currency = (string) $sale['currency'];
        $customerId = $sale['customer_id'] ?? null;
        $credit = 0;

        foreach ($sale['payments'] ?? [] as $payment) {
            if (($payment['method_type'] ?? null) === 'credit') {
                $credit += (int) $payment['amount_in_sale_minor'];
            }
        }

        $limit = null;
        $before = null;
        $decision = null;

        if ($credit > 0) {
            $customer = $customerId === null ? null : Party::query()->find($customerId);
            $before = $customer === null ? 0 : $this->ledger->balance($customer->id, $currency, $soldAt);
            $judged = CreditCheck::judge(
                $credit, $currency, $customer !== null, $customer?->credit_limit_minor === null ? null : (int) $customer->credit_limit_minor,
                $customer?->credit_limit_currency, $before, is_array($ext['credit'] ?? null) ? $ext['credit'] : null,
                (bool) ($sale['offline'] ?? false), $soldAt, CarbonImmutable::now(), Settings::for($van->company_id)['flag_offline_credit'],
            );
            [$flags, $limit, $decision] = [[...$flags, ...$judged['flags']], $judged['limit_minor'], $judged['decision']];
        }

        $visitId = $run !== null && is_array($ext) && ! empty($ext['visit_id']) && Str::isUuid((string) $ext['visit_id'])
            ? Visit::query()->where('run_id', $run->id)->whereKey($ext['visit_id'])->value('id') : null;
        $link = VanSale::create([
            'company_id' => $van->company_id,
            'run_id' => $run?->id,
            'visit_id' => $visitId,
            'sale_id' => $saleId,
            'customer_id' => $customerId,
            'exchange_for_sale_id' => is_array($ext) && ! empty($ext['exchange_for_sale_id']) && Str::isUuid((string) $ext['exchange_for_sale_id']) ? $ext['exchange_for_sale_id'] : null,
            'currency' => $currency,
            'total_minor' => (int) $sale['totals']['total_minor'],
            'credit_minor' => $credit,
            'status' => 'completed',
            'flags' => $flags,
            'credit_decision' => $decision,
            'limit_minor' => $limit,
            'balance_before_minor' => $before,
            'review_status' => $flags === [] ? null : 'pending',
            'sold_at' => $soldAt,
        ]);

        if ($visitId !== null) {
            Visit::query()->whereKey($visitId)->update(['status' => 'sold', 'no_sale_reason' => null]);
        }

        if ($run !== null) {
            $quantities = [];

            foreach ($sale['lines'] ?? [] as $line) {
                $quantities[$line['item_id']] = ($quantities[$line['item_id']] ?? Qty::zero())->plus($this->units->toBase($line['item_id'], $line['uom_id'] ?? null, (string) $line['qty']));
            }

            foreach ($quantities as $itemId => $qty) {
                $this->stock($run->id, 'sold', 'sale', $saleId, $itemId, $qty);
            }

            foreach ($this->tenderTotals($sale['payments'] ?? []) as [$kind, $cur, $amount]) {
                $this->tender($run->id, 'sale', $saleId, $kind, $cur, $amount);
            }

            $change = (int) ($sale['change']['amount_minor'] ?? 0);

            if ($change > 0) {
                $this->tender($run->id, 'sale_change', $saleId, 'cash', (string) ($sale['change']['currency'] ?? $currency), -$change);
            }
        }

        // Credit is ledgered whatever the run: the customer owes it.
        if ($credit > 0 && $customerId !== null) {
            $this->ledger->add($van->company_id, $customerId, $run?->id, 'sale', $saleId, $currency, $credit, $soldAt);
        }

        if ($flags !== []) {
            $this->auditor->record('van_sales.sale.flagged', $link, null, ['flags' => $flags, 'credit_minor' => (string) $credit, 'currency' => $currency]);
        }

        if ($run !== null) {
            $this->reopening->late($run, 'late_sale');
        }
    }

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function voided(string $saleId, array $sale, ?string $voidedAt): void
    {
        $link = VanSale::query()->where('sale_id', $saleId)->lockForUpdate()->first();

        if ($link === null || $link->status === 'voided') {
            return;
        }

        $link->forceFill(['status' => 'voided', 'flags' => array_values(array_unique([...$link->flags, 'voided']))])->save();
        $at = CarbonImmutable::parse($voidedAt ?? now())->utc();

        if ($link->run_id !== null) {
            foreach (StockLine::query()->where('source_type', 'sale')->where('source_id', $saleId)->get() as $line) {
                $this->stock($link->run_id, 'sold', 'void', $saleId, $line->item_id, Qty::of((string) $line->qty)->negated());
            }

            foreach ($this->tenderTotals($sale['payments'] ?? []) as [$kind, $cur, $amount]) {
                $this->tender($link->run_id, 'void', $saleId, $kind, $cur, -$amount);
            }

            $change = (int) ($sale['change']['amount_minor'] ?? 0);

            if ($change > 0) {
                $this->tender($link->run_id, 'void_change', $saleId, 'cash', (string) ($sale['change']['currency'] ?? $link->currency), $change);
            }
        }

        if ($link->credit_minor > 0 && $link->customer_id !== null) {
            $this->ledger->add($link->company_id, $link->customer_id, $link->run_id, 'sale_void', $saleId, $link->currency, -(int) $link->credit_minor, $at);
        }

        if ($link->run_id !== null && ($run = Run::query()->find($link->run_id)) !== null) {
            $this->reopening->late($run, 'late_void');
        }
    }

    /**
     * A refund at a van's location belongs to the run the van had open (or
     * last had) when it was made: its goods are back on the van and count in
     * the run's load, its payments leave the run's cash or credit.
     *
     * @param  array<string, mixed>  $refund  SaleSnapshot::refund()
     */
    public function refunded(string $refundId, array $refund): void
    {
        $van = Van::query()->where('location_id', $refund['location_id'] ?? '')->first();

        if ($van === null) {
            return;
        }

        $at = CarbonImmutable::parse($refund['refunded_at'])->utc();
        $run = Run::query()->where('van_id', $van->id)->where('started_at', '<=', $at)->orderByDesc('started_at')->first();

        if ($run === null) {
            return;
        }

        $stock = SaleStockLines::forRefund($refundId);
        $quantities = [];

        foreach ($stock['lines'] ?? [] as $line) {
            $quantities[$line['item_id']] = ($quantities[$line['item_id']] ?? Qty::zero())->plus($this->units->toBase($line['item_id'], $line['uom_id'] ?? null, (string) $line['qty']));
        }

        foreach ($quantities as $itemId => $qty) {
            $this->stock($run->id, 'customer_return', 'refund', $refundId, $itemId, $qty);
        }

        $credit = 0;

        foreach ($this->tenderTotals($refund['payments'] ?? []) as [$kind, $cur, $amount]) {
            $this->tender($run->id, 'refund', $refundId, $kind, $cur, -$amount);
        }

        foreach ($refund['payments'] ?? [] as $payment) {
            if (($payment['method_type'] ?? null) === 'credit') {
                $credit += (int) $payment['amount_in_sale_minor'];
            }
        }

        if ($credit > 0 && ! empty($refund['customer_id'])) {
            $this->ledger->add($run->company_id, $refund['customer_id'], $run->id, 'refund', $refundId, (string) $refund['currency'], -$credit, $at);
        }

        $this->reopening->late($run, 'late_refund');
    }

    /**
     * Cash and mobile money by currency; credit and the other kinds are not money in the van's hands.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function tenderTotals(array $payments): array
    {
        $totals = [];

        foreach ($payments as $payment) {
            $kind = $payment['method_type'] ?? null;

            if (! in_array($kind, ['cash', 'mobile_money'], true)) {
                continue;
            }

            $key = $kind.'|'.$payment['currency'];
            $totals[$key] = [$kind, (string) $payment['currency'], ($totals[$key][2] ?? 0) + (int) $payment['amount_minor']];
        }

        return array_values($totals);
    }

    private function stock(string $runId, string $kind, string $sourceType, string $sourceId, string $itemId, BigDecimal $qty): void
    {
        StockLine::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenants->require(), 'run_id' => $runId, 'kind' => $kind,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'item_id' => $itemId, 'qty' => (string) Qty::of($qty),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tender(string $runId, string $sourceType, string $sourceId, string $kind, string $currency, int $amount): void
    {
        Tender::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenants->require(), 'run_id' => $runId, 'source_type' => $sourceType,
            'source_id' => $sourceId, 'kind' => $kind, 'currency' => $currency, 'amount_minor' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
