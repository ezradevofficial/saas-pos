<?php

namespace Modules\Procurement\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-14, PRC-15, PRC-25: goods received notes against approved POs and
// supplier invoices matched three ways (PO, GRN, invoice), with the
// events Inventory and Accounting hear.
class ReceiptsAndInvoicesTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
    }

    public function test_a_grn_receives_an_approved_po_values_it_and_raises_its_events(): void
    {
        Event::fake([GoodsReceiptPosted::class, GoodsReceived::class]);
        $order = $this->approvedOrder();
        [$soap, $delivery] = $order['lines'];

        // A draft PO cannot be received.
        $draft = $this->draftOrder();
        $this->api('POST', 'procurement/receipts', ['order_id' => $draft['id'], 'lines' => [['order_line_id' => $draft['lines'][0]['id'], 'received_qty' => '1']]])
            ->assertUnprocessable()->assertJsonPath('code', 'po_not_approved');

        $receipt = $this->api('POST', 'procurement/receipts', [
            'order_id' => $order['id'],
            'delivery_note' => 'DN-778',
            'lines' => [
                ['order_line_id' => $soap['id'], 'received_qty' => '6', 'rejected_qty' => '1', 'reject_reason' => 'Crushed', 'batch_number' => 'LOT-9'],
                ['order_line_id' => $delivery['id'], 'received_qty' => '1'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $receipt['status']);
        $posted = $this->api('POST', "procurement/receipts/{$receipt['id']}/post")->assertOk()->json('data');

        $this->assertSame('posted', $posted['status']);
        $this->assertMatchesRegularExpression('/^GRN-/', $posted['number']);
        // 5 accepted × KES 100.00 (net) + the delivery's 500.00.
        $this->assertSame('50000', $posted['lines'][0]['value']['amount_minor']);
        $this->assertSame('50000', $posted['lines'][1]['value']['amount_minor']);
        $this->assertTrue($posted['lines'][0]['on_time']);
        $after = $this->api('GET', "procurement/orders/{$order['id']}")->json('data');
        $this->assertSame('partially_received', $after['status']);
        $this->assertSame('6', $after['lines'][0]['received_qty']);
        $this->assertSame('1', $after['lines'][0]['rejected_qty']);

        Event::assertDispatched(GoodsReceiptPosted::class, fn (GoodsReceiptPosted $e) => $e->amounts === ['stock_value' => '50000', 'expense' => '50000', 'grni' => '100000', 'landed_cost' => '0']
            && $e->currency === 'KES' && $e->dimensions['party_id'] === $this->supplier->id);
        // Inventory is not active here: no GoodsReceived.
        Event::assertNotDispatched(GoodsReceived::class);

        // Over-receipt is refused beyond the allowance (0 by default), then within it.
        $more = $this->api('POST', 'procurement/receipts', ['order_id' => $order['id'], 'lines' => [['order_line_id' => $soap['id'], 'received_qty' => '6']]])->assertCreated()->json('data');
        $this->api('POST', "procurement/receipts/{$more['id']}/post")->assertUnprocessable()->assertJsonPath('code', 'over_receipt');
        $this->inTenant(fn () => Settings::query()->create(['company_id' => $this->acme->id, 'over_receipt_percent' => '10']));
        $this->api('POST', "procurement/receipts/{$more['id']}/post")->assertOk();
        $this->assertSame('received', $this->api('GET', "procurement/orders/{$order['id']}")->json('data.status'));
    }

    public function test_with_inventory_active_the_grn_moves_stock_at_its_base_cost(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $order = $this->approvedOrder(['currency' => 'USD'], [['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2', 'unit_price_minor' => '1200']]);
        $this->rate('USD', 'KES', '131', at: '2026-10-15T08:00:00Z');
        $this->postedReceipt($order);

        // 2 boxes of 12 = 24 soaps at USD 24.00 = KES 3,144.00 (the receipt date's rate, 131).
        $this->assertSame('24', $this->inTenant(fn () => Qty::show((string) Balance::query()->where('item_id', $this->soap->id)->value('qty'))));
        $movement = $this->inTenant(fn () => Movement::query()->where('source_type', 'procurement.goods_receipt')->sole());
        $this->assertSame(314400, (int) $movement->value_minor);
        $this->assertSame('KES', $movement->currency);
    }

    public function test_a_three_way_match_and_the_approval_of_a_matched_invoice(): void
    {
        Event::fake([SupplierInvoiceApproved::class]);
        $order = $this->approvedOrder();
        [$soap, $delivery] = $order['lines'];
        $this->postedReceipt($order, [['order_line_id' => $soap['id'], 'received_qty' => '8'], ['order_line_id' => $delivery['id'], 'received_qty' => '1']]);

        // Price above the PO, quantity above what arrived: an exception per line.
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'INV-5501', 'invoice_date' => '2026-10-16',
            'lines' => [['order_line_id' => $soap['id'], 'qty' => '10', 'unit_price_minor' => '10500'], ['order_line_id' => $delivery['id'], 'qty' => '1']],
        ])->assertCreated()->json('data');
        $this->assertSame('exception', $invoice['status']);
        $this->assertSame('qty_exceeds_received', $invoice['lines'][0]['match_status']);
        $this->assertSame('matched', $invoice['lines'][1]['match_status']);

        $invoice = $this->api('PATCH', "procurement/invoices/{$invoice['id']}", ['lines' => [['order_line_id' => $soap['id'], 'qty' => '8', 'unit_price_minor' => '10500'], ['order_line_id' => $delivery['id'], 'qty' => '1']]])->assertOk()->json('data');
        $this->assertSame('price_variance', $invoice['lines'][0]['match_status']);
        // (105.00 − 100.00) × 8 = 40.00.
        $this->assertSame('4000', $invoice['lines'][0]['price_variance']['amount_minor']);
        // Within a 5 % price tolerance it matches.
        $this->inTenant(fn () => Settings::query()->create(['company_id' => $this->acme->id, 'price_tolerance_percent' => '5']));
        $invoice = $this->api('PATCH', "procurement/invoices/{$invoice['id']}", ['due_date' => '2026-11-15'])->assertOk()->json('data');
        $this->assertSame('matched', $invoice['status']);

        // The same supplier number twice is refused.
        $this->api('POST', 'procurement/invoices', ['order_id' => $order['id'], 'supplier_reference' => 'inv-5501', 'invoice_date' => '2026-10-16',
            'lines' => [['order_line_id' => $delivery['id'], 'qty' => '1']]])->assertUnprocessable()->assertJsonPath('code', 'duplicate_reference');

        // Buyers do not approve; the accountant does.
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $this->buyer)->assertForbidden();
        $approved = $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $accountant)->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertMatchesRegularExpression('/^PINV-/', $approved['number']);
        // 8 × 105.00 + 16 % VAT and the 500.00 delivery: net 1,340.00, tax 134.40.
        $this->assertSame('134000', $approved['subtotal']['amount_minor']);
        $this->assertSame('13440', $approved['tax']['amount_minor']);
        // Decision 16: Inventory is off, so no receipt journal credited GRNI: the invoice clears none, all of it is purchases.
        $this->assertSame('0', $approved['base_grni']['amount_minor']);
        $this->assertSame('8', $this->api('GET', "procurement/orders/{$order['id']}")->json('data.lines.0.invoiced_qty'));

        Event::assertDispatched(SupplierInvoiceApproved::class, function (SupplierInvoiceApproved $e) {
            return $e->amounts === ['net' => '134000', 'tax' => '13440', 'total' => '147440', 'grni' => '0', 'price_variance' => '0']
                && $e->document['supplier_reference'] === 'INV-5501' && $e->document['lines'][0]['kind'] === 'expense' && $e->document['lines'][1]['kind'] === 'expense';
        });
    }

    public function test_an_exception_needs_the_override_permission_and_a_reason(): void
    {
        $order = $this->approvedOrder();
        $this->postedReceipt($order, [['order_line_id' => $order['lines'][0]['id'], 'received_qty' => '10']]);
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'X-1', 'invoice_date' => '2026-10-16',
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'qty' => '10', 'unit_price_minor' => '11000'], ['order_line_id' => $order['lines'][1]['id'], 'qty' => '1']],
        ])->assertCreated()->json('data');
        $this->assertSame('exception', $invoice['status']);
        // The delivery was never received.
        $this->assertSame('not_received', $invoice['lines'][1]['match_status']);

        $approver = $this->userWith('approver', Scope::company($this->acme->id));
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $approver)->assertForbidden()->assertJsonPath('code', 'match_exception');
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve")->assertUnprocessable()->assertJsonPath('code', 'override_reason_required');
        $approved = $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Agreed price rise; delivery billed in advance'])->assertOk()->json('data');
        $this->assertSame('Agreed price rise; delivery billed in advance', $approved['override_reason']);
    }
}
