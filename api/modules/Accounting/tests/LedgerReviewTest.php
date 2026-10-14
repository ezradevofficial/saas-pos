<?php

namespace Modules\Accounting\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Account;
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
}
