<?php

namespace Modules\Accounting\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * Stage 7B closing review of the ledger (ACC-27, ACC-29, ACC-31, ACC-32;
 * procurement.md decisions 16 to 20): GRNI nets to zero whatever the
 * modules were between receipt and invoice, invoices clear GRNI
 * cumulatively, landed costs clear through their own bills, and the
 * backfill covers every module.
 */
class LedgerReviewTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private function setUpBooks(bool $inventory = true): void
    {
        $this->setUpProcurement(inventory: $inventory);
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(Charts::class)->fromTemplate($this->acme);
            Settings::query()->create(['company_id' => $this->acme->id, 'price_tolerance_percent' => '5']);
        });
    }

    private function module(string $module, bool $on): void
    {
        $this->inTenant(fn () => $on ? app(ModuleRegistry::class)->activate($module) : app(ModuleRegistry::class)->deactivate($module));
    }

    private function invoice(array $order, array $lines, string $reference, array $extra = []): array
    {
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => $reference, 'invoice_date' => '2026-10-16', 'lines' => $lines,
        ])->assertCreated()->json('data');

        return $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", $extra)->assertOk()->json('data');
    }

    /** Every PO line invoiced in full at the PO price. */
    private function invoiceAll(array $order, string $reference): array
    {
        return $this->invoice($order, array_map(fn (array $l) => ['order_line_id' => $l['id'], 'qty' => $l['qty']], $order['lines']), $reference);
    }

    protected function balance(string $code): int
    {
        return $this->inTenant(fn () => (int) JournalLine::query()->where('account_id', Account::query()->where('company_id', $this->acme->id)->where('code', $code)->value('id'))
            ->selectRaw('coalesce(sum(base_debit_minor - base_credit_minor), 0) as balance')->value('balance'));
    }

    public function test_grni_nets_when_inventory_is_switched_off_between_receipt_and_invoice(): void
    {
        $this->setUpBooks();
        $order = $this->approvedOrder();
        $this->postedReceipt($order);
        $this->assertSame(-100000, $this->balance('2130'));

        $this->module('inventory', false);
        $this->invoiceAll($order, 'OFF-1');

        $this->assertSame(0, $this->balance('2130'));
        // The soaps' net met GRNI; the delivery is purchases.
        $this->assertSame(50000, $this->balance('5200'));
    }

    public function test_grni_nets_when_inventory_is_switched_on_between_receipt_and_invoice(): void
    {
        $this->setUpBooks(inventory: false);
        $order = $this->approvedOrder();
        $this->postedReceipt($order);
        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->count()));

        $this->module('inventory', true);
        $this->invoiceAll($order, 'ON-1');

        // Nothing was received into stock: the whole invoice is purchases, GRNI untouched.
        $this->assertSame(0, $this->balance('2130'));
        $this->assertSame(150000, $this->balance('5200'));
    }

    public function test_an_invoice_in_three_parts_leaves_grni_at_zero(): void
    {
        $this->setUpBooks();
        $order = $this->approvedOrder(lines: [['item_id' => $this->soap->id, 'qty' => '3', 'unit_price_minor' => '3333']]);
        $this->postedReceipt($order);
        $this->assertSame(-9999, $this->balance('2130'));

        foreach (['A', 'B', 'C'] as $reference) {
            $this->invoice($order, [['order_line_id' => $order['lines'][0]['id'], 'qty' => '1']], "PART-{$reference}");
        }

        $this->assertSame(0, $this->balance('2130'));
    }

    public function test_receipts_at_different_rates_net_in_grni(): void
    {
        $this->setUpBooks();
        $this->rate('USD', 'KES', '125', at: '2026-10-10T00:00:00Z');
        $order = $this->approvedOrder(['currency' => 'USD'], [['item_id' => $this->soap->id, 'qty' => '3', 'unit_price_minor' => '100']]);
        $line = $order['lines'][0]['id'];
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '1']], ['received_at' => '2026-10-05T10:00:00Z']);
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '2']], ['received_at' => '2026-10-12T10:00:00Z']);
        $this->assertSame(-38000, $this->balance('2130'));

        $this->invoice($order, [['order_line_id' => $line, 'qty' => '2']], 'USD-1');
        $this->invoice($order, [['order_line_id' => $line, 'qty' => '1']], 'USD-2');

        $this->assertSame(0, $this->balance('2130'));
    }

    /** A posted landed cost of KES $amount (minor) by value over $receiptIds, billed by the other supplier as $bill. */
    private function landedCost(array $receiptIds, string $amount, string $bill): array
    {
        $landed = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'bill_reference' => $bill, 'currency' => 'KES', 'amount_minor' => $amount]],
            'receipt_ids' => $receiptIds])->assertCreated()->json('data');

        return $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertOk()->json('data');
    }

    /** @return array{0: array, 1: array} a draft GRN of the whole PO (soap and delivery), with KES 300.00 of freight allocated before it posts, then posted */
    private function receiveWithLandedCosts(): array
    {
        $order = $this->approvedOrder();
        $draft = $this->api('POST', 'procurement/receipts', ['order_id' => $order['id'],
            'lines' => array_map(fn (array $l) => ['order_line_id' => $l['id'], 'received_qty' => $l['qty']], $order['lines'])])->assertCreated()->json('data');
        // Before receipt: KES 300.00 by value (soap 1,000.00 and delivery 500.00): 200.00 and 100.00.
        $this->landedCost([$draft['id']], '30000', 'FR-100');
        $receipt = $this->api('POST', "procurement/receipts/{$draft['id']}/post")->assertOk()->json('data');
        // After receipt: KES 150.00 by value: 100.00 and 50.00.
        $this->landedCost([$receipt['id']], '15000', 'FR-101');

        return [$order, $receipt];
    }

    public function test_landed_costs_before_and_after_receipt_clear_through_their_bills(): void
    {
        $this->setUpBooks();
        [$order] = $this->receiveWithLandedCosts();

        // The receipt journal credits the goods to GRNI and their landed part to clearing.
        $receipt = $this->inTenant(fn () => Journal::query()->where('source_type', 'inventory.receipt')->sole());
        $this->assertSame(['1300' => [120000, 0], '2130' => [0, 100000], '2140' => [0, 20000]], $this->journalLines($receipt));
        // Stock: 1,000.00 goods, 200.00 landed before, 100.00 landed after; the delivery's 150.00 is expense.
        $this->assertSame(130000, $this->balance('1300'));
        $this->assertSame(15000, $this->balance('5200'));
        // Two bills of the carrier, each a posted Accounting supplier invoice: clearing nets to zero.
        $bills = $this->inTenant(fn () => Document::query()->where('party_id', $this->otherSupplier->id)->orderBy('supplier_reference')->get());
        $this->assertSame([['FR-100', 30000, 'posted'], ['FR-101', 15000, 'posted']], $bills->map(fn (Document $d) => [$d->supplier_reference, (int) $d->open_minor, $d->status])->all());
        $this->assertSame(-45000, $this->balance('2110'));
        $this->assertSame(0, $this->balance('2140'));

        // The supplier's invoice clears GRNI: nothing is left there either.
        $this->invoiceAll($order, 'GOODS-1');
        $this->assertSame(0, $this->balance('2130'));
    }

    public function test_with_inventory_off_landed_costs_are_purchases_and_clearing_still_nets(): void
    {
        $this->setUpBooks(inventory: false);
        [$order] = $this->receiveWithLandedCosts();

        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->where('source_type', 'like', 'inventory.%')->count()));
        // All KES 450.00 of freight is purchases, against clearing, which the two bills clear.
        $this->assertSame(45000, $this->balance('5200'));
        $this->assertSame(0, $this->balance('2140'));
        $this->assertSame(-45000, $this->balance('2110'));

        $this->invoiceAll($order, 'GOODS-2');
        $this->assertSame(0, $this->balance('2130'));
    }

    public function test_a_landed_cost_needs_its_supplier_and_bill_reference_to_post(): void
    {
        $this->setUpBooks();
        $receipt = $this->postedReceipt($this->approvedOrder());
        $landed = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => [$receipt['id']]])->assertCreated()->json('data');

        $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertUnprocessable()->assertJsonPath('code', 'charge_supplier_required');
        $this->api('PATCH', "procurement/landed-costs/{$landed['id']}", ['charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'currency' => 'KES', 'amount_minor' => '1000']]])->assertOk();
        $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertUnprocessable()->assertJsonPath('code', 'charge_bill_required');
    }

    /** @return array<string, array{0: int, 1: int}> */
    private function journalLines(Journal $journal): array
    {
        return $this->inTenant(function () use ($journal) {
            $lines = [];

            foreach (JournalLine::query()->where('journal_id', $journal->id)->get() as $line) {
                $code = Account::query()->whereKey($line->account_id)->value('code');
                $lines[$code] = [($lines[$code][0] ?? 0) + (int) $line->base_debit_minor, ($lines[$code][1] ?? 0) + (int) $line->base_credit_minor];
            }

            ksort($lines);

            return $lines;
        });
    }
}
