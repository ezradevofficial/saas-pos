<?php

namespace Modules\Procurement\Tests;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Procurement\Events\PurchaseOrderApproved;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-13, PRC-16, PRC-19, PRC-22: purchase orders, multi-currency, through
// the `procurement.po` flow, numbered, scoped by branch.
class OrdersTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
    }

    public function test_a_po_is_drafted_submitted_approved_numbered_and_frozen_in_base_currency(): void
    {
        Event::fake([PurchaseOrderApproved::class]);

        $order = $this->draftOrder();
        $this->assertSame('draft', $order['status']);
        $this->assertNull($order['number']);
        // 10 × 100.00 + 16 % VAT, plus a delivery of 500.00 without tax.
        $this->assertSame(['amount_minor' => '150000', 'currency' => 'KES'], $order['subtotal']);
        $this->assertSame(['amount_minor' => '16000', 'currency' => 'KES'], $order['tax']);
        $this->assertSame(['amount_minor' => '166000', 'currency' => 'KES'], $order['total']);

        $submitted = $this->api('POST', "procurement/orders/{$order['id']}/submit", [], $this->buyer)->assertOk()->json('data');
        $this->assertSame('pending', $submitted['status']);
        $this->assertMatchesRegularExpression('/^PO-.+-2026-00001$/', $submitted['number']);
        $this->assertSame(['amount_minor' => '166000', 'currency' => 'KES'], $submitted['base_total']);

        // APR-07: the requester cannot approve it; the manager does.
        $this->approveFlow('procurement.po', $order['id']);
        $this->api('GET', "procurement/orders/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'approved');
        Event::assertDispatched(PurchaseOrderApproved::class, fn (PurchaseOrderApproved $e) => $e->orderId === $order['id'] && $e->baseTotalMinor === '166000');
    }

    public function test_a_usd_po_freezes_the_rate_of_its_order_date(): void
    {
        $order = $this->draftOrder(['currency' => 'USD'], [['item_id' => $this->soap->id, 'qty' => '3', 'unit_price_minor' => '1050']]);
        $submitted = $this->api('POST', "procurement/orders/{$order['id']}/submit", [], $this->buyer)->assertOk()->json('data');

        // 3 × USD 10.50 = USD 31.50, at 130 = KES 4,095.00 (CUR-04).
        $this->assertSame(['amount_minor' => '3150', 'currency' => 'USD'], $submitted['total']);
        $this->assertSame(['amount_minor' => '409500', 'currency' => 'KES'], $submitted['base_total']);
        $this->assertSame('130', rtrim(rtrim($submitted['fx']['rate'], '0'), '.'));

        // A later rate never changes it.
        $this->rate('USD', 'KES', '140', at: '2026-10-15T08:00:00Z');
        $this->assertSame('409500', $this->api('GET', "procurement/orders/{$order['id']}")->json('data.base_total.amount_minor'));
    }

    public function test_a_missing_rate_and_a_rate_needed_tax_code_refuse_submission(): void
    {
        $this->inTenant(fn () => app(\App\Core\Currency\TenantCurrencies::class)->activate('EUR'));
        $eur = $this->draftOrder(['currency' => 'EUR'], [['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '100']]);
        $this->api('POST', "procurement/orders/{$eur['id']}/submit", [], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'rate_unavailable');

        $needed = $this->inTenant(function () {
            $code = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'EXC_T', 'name' => 'Excise test', 'kind' => 'excise']);
            TaxRate::create(['tax_code_id' => $code->id, 'rate' => null, 'effective_from' => '2026-01-01', 'needs_confirmation' => true]);

            return $code;
        });
        $order = $this->draftOrder([], [['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '100', 'tax_code_id' => $needed->id]]);
        $this->assertTrue($order['lines'][0]['rate_needed']);
        $this->api('POST', "procurement/orders/{$order['id']}/submit", [], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'tax_rate_missing');
    }

    public function test_only_suppliers_of_the_company_and_items_of_it_are_taken(): void
    {
        $this->api('POST', 'procurement/orders', [
            'branch_id' => $this->branchA->id, 'party_id' => $this->customer->id,
            'lines' => [['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '100']],
        ], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'party_not_supplier');

        $this->api('POST', 'procurement/orders', [
            'branch_id' => $this->branchA->id, 'party_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->soap->id, 'uom_id' => $this->inTenant(fn () => \App\Core\MasterData\Items\Uom::where('code', 'KG')->value('id')), 'qty' => '1', 'unit_price_minor' => '100']],
        ], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'uom_not_on_item');
    }

    public function test_permissions_and_branch_scope(): void
    {
        $order = $this->draftOrder();
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $buyerB = $this->userWith('procurement_officer', Scope::branch($this->branchB->id));

        // RBAC-04: out of scope or without the permission is not found; the list shows nothing.
        $this->api('GET', "procurement/orders/{$order['id']}", [], $buyerB)->assertNotFound();
        $this->api('GET', "procurement/orders/{$order['id']}", [], $cashier)->assertNotFound();
        $this->assertSame([], $this->api('GET', 'procurement/orders', [], $buyerB)->assertOk()->json('data'));
        $this->api('GET', 'procurement/orders', [], $cashier)->assertForbidden();
        // A buyer at branch B cannot raise a PO at branch A.
        $this->api('POST', 'procurement/orders', [
            'branch_id' => $this->branchA->id, 'party_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '100']],
        ], $buyerB)->assertForbidden();
        // Buyers do not approve POs (RBAC-03).
        $this->assertFalse($this->inTenant(fn () => app(\App\Core\Rbac\ScopeResolver::class)->can($this->buyer, 'procurement.po.approve', Scope::branch($this->branchA->id))));

        // RBAC-08: with the module off, every route is closed.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('procurement'));
        $this->api('GET', 'procurement/orders')->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_another_tenants_po_and_ids_are_not_found(): void
    {
        $order = $this->draftOrder();
        $other = $this->otherTenant();
        $this->inTenant(fn () => null);
        $foreign = $this->asTenant($other['user']->tenant_id, function () {
            app(ModuleRegistry::class)->activate('procurement');

            return null;
        });

        $this->getJson("/api/v1/procurement/orders/{$order['id']}", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson("/api/v1/procurement/orders/{$order['id']}/cancel", ['reason' => 'x'], $this->headersFor($other['user']))->assertNotFound();
        // A body naming the other tenant's branch is refused.
        $this->api('POST', 'procurement/orders', [
            'branch_id' => $other['branch']->id, 'party_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '100']],
        ])->assertUnprocessable();
        $this->assertNull($foreign);
        $this->assertSame(1, $this->inTenant(fn () => Order::query()->count()));
    }

    public function test_cancel_and_the_pdf(): void
    {
        $order = $this->approvedOrder();
        $this->get("/api/v1/procurement/orders/{$order['id']}/pdf", $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->api('POST', "procurement/orders/{$order['id']}/send")->assertOk()->assertJsonPath('data.sent_at', fn ($v) => $v !== null);
        $this->api('POST', "procurement/orders/{$order['id']}/cancel", ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api('POST', "procurement/orders/{$order['id']}/cancel", ['reason' => 'Again'])->assertStatus(409);
    }
}
