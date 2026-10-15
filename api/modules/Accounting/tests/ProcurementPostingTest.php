<?php

namespace Modules\Accounting\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ACC-32, PRC-15, PRC-25 (procurement.md decision 3): a PO, its GRN and
 * the supplier's invoice, end to end with Procurement, Inventory and
 * Accounting on. The GRN posts once, from Inventory's StockMoved
 * (`inventory.receipt`: Dr stock, Cr GRNI); the approved invoice becomes a
 * posted Accounting supplier invoice (Dr GRNI, the price variance, the
 * purchases of services and input tax; Cr the supplier's payable), so
 * GRNI clears to zero.
 */
class ProcurementPostingTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private ?SupplierInvoiceApproved $approved = null;

    private function setUpBooks(bool $accounting = true): void
    {
        $this->setUpProcurement(inventory: true);

        if ($accounting) {
            $this->inTenant(function () {
                app(ModuleRegistry::class)->activate('accounting');
                app(Charts::class)->fromTemplate($this->acme);
            });
        }

        // Prices within 5 % of the PO match (the invoice below is 5 % over on soap).
        $this->inTenant(fn () => Settings::query()->create(['company_id' => $this->acme->id, 'price_tolerance_percent' => '5']));
        Event::listen(SupplierInvoiceApproved::class, function (SupplierInvoiceApproved $event) {
            $this->approved = $event;
        });
    }

    /** PO: 10 soaps at KES 100.00 (synthetic 16 % VAT) and a delivery at KES 500.00; all received; invoiced with soap at KES 105.00. */
    private function buyReceiveAndInvoice(): array
    {
        $order = $this->approvedOrder();
        [$soap, $delivery] = $order['lines'];
        $this->postedReceipt($order);
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'INV-7001', 'invoice_date' => '2026-10-16',
            'lines' => [['order_line_id' => $soap['id'], 'qty' => '10', 'unit_price_minor' => '10500'], ['order_line_id' => $delivery['id'], 'qty' => '1']],
        ])->assertCreated()->json('data');
        $this->assertSame('matched', $invoice['status']);

        return $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $this->userWith('accountant', Scope::company($this->acme->id)))->assertOk()->json('data');
    }

    /** @return array<string, array{0: int, 1: int}> a journal's lines by account code: [base debit, base credit] */
    private function linesOf(Journal $journal): array
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

    private function balance(string $code): int
    {
        return $this->inTenant(fn () => (int) JournalLine::query()->where('account_id', Account::query()->where('company_id', $this->acme->id)->where('code', $code)->value('id'))
            ->selectRaw('coalesce(sum(base_debit_minor - base_credit_minor), 0) as balance')->value('balance'));
    }

    private function journal(string $type): Journal
    {
        return $this->inTenant(fn () => Journal::query()->where('source_type', $type)->sole());
    }

    public function test_a_po_its_grn_and_its_invoice_clear_grni_to_zero_and_leave_the_payable_in_accounting(): void
    {
        $this->setUpBooks();
        $invoice = $this->buyReceiveAndInvoice();

        // The GRN posts once, from StockMoved: stock in at the PO price, GRNI credited (the delivery is a service: no stock).
        $this->assertSame(['1300' => [100000, 0], '2130' => [0, 100000]], $this->linesOf($this->journal('inventory.receipt')));
        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->where('source_type', 'procurement.goods_receipt')->count()));

        // The invoice: GRNI cleared, KES 50.00 price variance, the delivery to purchases, 16 % input tax on soap, the payable.
        $journal = $this->journal('procurement.supplier_invoice');
        $this->assertSame([
            '1400' => [16800, 0], '2110' => [0, 171800], '2130' => [100000, 0], '5100' => [5000, 0], '5200' => [50000, 0],
        ], $this->linesOf($journal));
        $this->assertSame(0, $this->balance('2130'));

        // The payable is an Accounting supplier invoice, open for its total, linked to the journal.
        $document = $this->inTenant(fn () => Document::query()->sole());
        $this->assertSame(['supplier_invoice', 'posted', $this->supplier->id, 'INV-7001', 171800, 171800, $journal->id],
            [$document->kind, $document->status, $document->party_id, $document->supplier_reference, (int) $document->total_minor, (int) $document->open_minor, $document->journal_id]);
        $this->assertMatchesRegularExpression('/^BILL-2026-\d{5}$/', (string) $document->number);
        $this->assertSame(2, $this->inTenant(fn () => $document->lines()->count()));
        $this->assertSame($invoice['id'], $journal->source_id);

        // Delivered again (a retry, a second worker): one journal, one document.
        $this->inTenant(fn () => app(Integrations::class)->handle($this->approved));
        $this->inTenant(function () {
            $this->assertSame(1, Journal::query()->where('source_type', 'procurement.supplier_invoice')->count());
            $this->assertSame(1, Document::query()->count());
            $this->assertSame(0, PostingException::query()->count());
        });
    }

    public function test_without_a_rule_the_invoice_waits_as_an_exception_and_its_retry_writes_the_document(): void
    {
        $this->setUpBooks();
        $rule = $this->inTenant(function () {
            $rule = PostingRule::query()->where('event_type', 'procurement.supplier_invoice')->sole();
            $rule->forceFill(['archived_at' => now()])->save();

            return $rule;
        });
        $this->buyReceiveAndInvoice();
        $exception = $this->inTenant(fn () => PostingException::query()->where('source_type', 'procurement.supplier_invoice')->sole());
        $this->assertSame('posting_rule_missing', $exception->error_code);
        $this->assertSame(0, $this->inTenant(fn () => Document::query()->count()));

        $this->inTenant(fn () => $rule->forceFill(['archived_at' => null])->save());
        $this->api('POST', "accounting/posting-exceptions/{$exception->id}/retry")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame(1, $this->inTenant(fn () => Document::query()->count()));
        $this->assertSame(0, $this->balance('2130'));
    }

    public function test_a_landed_cost_after_receipt_moves_stock_value_against_grni(): void
    {
        $this->setUpBooks();
        $receipt = $this->postedReceipt($this->approvedOrder());
        $landed = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-16', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'bill_reference' => 'FRT-77', 'currency' => 'KES', 'amount_minor' => '20000']], 'receipt_ids' => [$receipt['id']]])->assertCreated()->json('data');
        $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertOk();

        // INV-25: the soaps (all still on hand) take the stock share of KES 200.00 by value (1,000 of 1,500) against landed cost clearing.
        $this->assertSame(['1300' => [13333, 0], '2140' => [0, 13333]], $this->linesOf($this->journal('inventory.landed_cost')));
        // The delivery's share is expensed against clearing; the freight bill credits the carrier and clears it to zero.
        $this->assertSame(['2140' => [0, 6667], '5200' => [6667, 0]], $this->linesOf($this->journal('procurement.landed_cost')));
        $this->assertSame(['2110' => [0, 20000], '2140' => [20000, 0]], $this->linesOf($this->journal('procurement.landed_cost_bill')));
        $this->assertSame(0, $this->balance('2140'));
        $this->assertSame(0, $this->balance('2130') + 100000, 'GRNI still holds only the goods received');
    }

    public function test_with_accounting_off_nothing_posts(): void
    {
        $this->setUpBooks(accounting: false);
        $this->buyReceiveAndInvoice();

        $this->inTenant(function () {
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, Document::query()->count());
            $this->assertSame(0, PostingException::query()->count());
        });
    }
}
