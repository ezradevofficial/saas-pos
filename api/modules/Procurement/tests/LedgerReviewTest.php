<?php

namespace Modules\Procurement\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Procurement\Models\InvoiceLine;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Modules\Stores\Models\Movement as StoreMovement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Stage 7B closing review (procurement.md decisions 16 to 18): a GRN reaches
// Stores when Inventory is off (INV-15, STR-07); each receipt line records
// whether its stock was tracked; an invoice clears GRNI cumulatively per PO
// line over tracked receipts only (PRC-15); a stock line cannot be invoiced
// before it is received, not even with the override.
class LedgerReviewTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private function invoice(array $order, array $lines, string $reference, array $extra = []): array
    {
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => $reference, 'invoice_date' => '2026-10-15', 'lines' => $lines,
        ])->assertCreated()->json('data');

        return $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", $extra)->assertOk()->json('data');
    }

    public function test_a_grn_reaches_stores_when_inventory_is_off(): void
    {
        $this->setUpProcurement();
        $store = $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('stores');

            return $this->branchA->locations()->create(['name' => 'Main store', 'type' => 'store']);
        });
        $order = $this->approvedOrder();
        $receipt = $this->postedReceipt($order, extra: ['location_id' => $store->id]);

        // Stores' own ledger received the 10 soaps (the delivery is a service); the line is not tracked: no Inventory, no GRNI.
        $this->inTenant(function () use ($receipt, $store) {
            $movement = StoreMovement::query()->where('source_id', $receipt['id'])->sole();
            $this->assertSame(['receipt', $store->id, $this->soap->id, '10'], [$movement->kind, $movement->location_id, $movement->item_id, rtrim(rtrim((string) $movement->qty, '0'), '.')]);
            $this->assertSame([false, false], ReceiptLine::query()->where('receipt_id', $receipt['id'])->orderBy('line_no')->pluck('stock_tracked')->all());
        });
    }

    public function test_a_receipt_line_records_whether_inventory_tracked_its_stock(): void
    {
        $this->setUpProcurement(inventory: true);
        $receipt = $this->postedReceipt($this->approvedOrder());

        // The soap went into Inventory; the delivery is a service.
        $this->assertSame([true, false], $this->inTenant(fn () => ReceiptLine::query()->where('receipt_id', $receipt['id'])->orderBy('line_no')->pluck('stock_tracked')->all()));
    }

    public function test_a_stock_line_not_received_is_refused_even_with_the_override_and_a_service_line_is_not(): void
    {
        $this->setUpProcurement(inventory: true);
        $order = $this->approvedOrder();
        [$soap, $delivery] = $order['lines'];

        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'ADV-1', 'invoice_date' => '2026-10-15',
            'lines' => [['order_line_id' => $soap['id'], 'qty' => '10'], ['order_line_id' => $delivery['id'], 'qty' => '1']],
        ])->assertCreated()->json('data');
        $this->assertSame(['not_received', 'not_received'], array_column($invoice['lines'], 'match_status'));
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Paid in advance'])
            ->assertUnprocessable()->assertJsonPath('code', 'stock_line_not_received');

        // The delivery alone (a service) may still be approved in advance with the override.
        $this->invoice($order, [['order_line_id' => $delivery['id'], 'qty' => '1']], 'ADV-2', ['override_reason' => 'Delivery billed in advance']);
    }

    public function test_grni_is_cleared_cumulatively_one_unit_at_a_time(): void
    {
        $this->setUpProcurement(inventory: true);
        // 3 soaps at KES 33.33: the receipt is worth KES 99.99, a third of it does not round evenly.
        $order = $this->approvedOrder(lines: [['item_id' => $this->soap->id, 'qty' => '3', 'unit_price_minor' => '3333']]);
        $receipt = $this->postedReceipt($order);
        $value = $this->inTenant(fn () => (int) ReceiptLine::query()->where('receipt_id', $receipt['id'])->sum('base_value_minor'));
        $this->assertSame(9999, $value);

        $cleared = [];

        foreach (['A', 'B', 'C'] as $reference) {
            $cleared[] = (int) $this->invoice($order, [['order_line_id' => $order['lines'][0]['id'], 'qty' => '1']], "ONE-{$reference}")['base_grni']['amount_minor'];
        }

        // 33.33 each; the invoice that reaches the received quantity clears the exact remainder.
        $this->assertSame([3333, 3333, 3333], $cleared);
        $this->assertSame($value, array_sum($cleared));
    }

    public function test_receipts_at_different_rates_are_cleared_in_full(): void
    {
        $this->setUpProcurement(inventory: true);
        $this->rate('USD', 'KES', '125', at: '2026-10-10T00:00:00Z');
        // 3 soaps at USD 1.00: one received at 130, two at 125.
        $order = $this->approvedOrder(['currency' => 'USD'], [['item_id' => $this->soap->id, 'qty' => '3', 'unit_price_minor' => '100']]);
        $line = $order['lines'][0]['id'];
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '1']], ['received_at' => '2026-10-05T10:00:00Z']);
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '2']], ['received_at' => '2026-10-12T10:00:00Z']);
        // KES 130.00 + 2 × 125.00.
        $value = $this->inTenant(fn () => (int) ReceiptLine::query()->where('order_line_id', $line)->sum('base_value_minor'));
        $this->assertSame(38000, $value);

        $first = (int) $this->invoice($order, [['order_line_id' => $line, 'qty' => '2']], 'USD-1')['base_grni']['amount_minor'];
        $second = (int) $this->invoice($order, [['order_line_id' => $line, 'qty' => '1']], 'USD-2')['base_grni']['amount_minor'];

        // round(380.00 × 2 / 3) then the remainder.
        $this->assertSame([25333, 12667], [$first, $second]);
        $this->assertSame($value, $this->inTenant(fn () => (int) InvoiceLine::query()->where('order_line_id', $line)->sum('base_grni_minor')));
    }

    public function test_untracked_receipts_clear_no_grni_whatever_the_modules_are_at_invoice_time(): void
    {
        $this->setUpProcurement();
        $order = $this->approvedOrder();
        $this->postedReceipt($order);
        // Inventory switched on after the receipt: the stock never went into it, so nothing is cleared.
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $approved = $this->invoice($order, array_map(fn (array $l) => ['order_line_id' => $l['id'], 'qty' => $l['qty']], $order['lines']), 'LATE-ON');

        $this->assertSame('0', $approved['base_grni']['amount_minor']);
        $this->assertSame([0, 0], $this->inTenant(fn () => InvoiceLine::query()->orderBy('line_no')->pluck('base_stock_net_minor')->map(fn ($v) => (int) $v)->all()));
    }
}
