<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Reservation;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-14, ECOM-16 to ECOM-22: the back office's orders: status flow,
// scope and permissions, cancellations, reservations and their expiry,
// phone orders, other tenants' ids.
class OrdersTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_cash_on_delivery_order_goes_through_its_statuses(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data');
        $id = $this->orderId($placed['number']);

        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->api('POST', "ecommerce/orders/{$id}/pick", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'picking');
        $this->api('POST', "ecommerce/orders/{$id}/ready", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'ready');

        // ECOM-18: a delivery order goes out only with a rider.
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'rider_required');
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $order = $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk()->json('data');

        $this->assertSame(['delivered', 'cod_collected'], [$order['status'], $order['payment_status']]);
        $this->assertSame(['placed', 'accepted', 'picking', 'ready', 'out_for_delivery', 'delivered'], array_column($order['history'], 'to'));
        $this->assertSame('136000', $order['delivery']['cod_due_minor']);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'ecommerce.order.update')->exists()), 'status changes are audited');
        $this->assertSame(0, $this->inTenant(fn () => Reservation::query()->where('order_id', $id)->whereNull('consumed_at')->count()), 'hand-over consumes the reservations');

        // A step out of order is refused.
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertStatus(409)->assertJsonPath('code', 'status_invalid');
    }

    public function test_an_unpaid_online_order_cannot_be_accepted_and_expires(): void
    {
        $this->stockUp('3');
        // Phone ending 111: the fake push never answers.
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Otieno', 'phone' => '0712000111']])->assertCreated()->json('data');
        $id = $this->orderId($placed['number']);
        $this->assertSame('pending', $placed['payment_status']);

        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'order_unpaid');

        // ECOM-14: past the store's 30 minutes, ecommerce:expire cancels it and frees the stock.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        Artisan::call('ecommerce:expire');

        $order = $this->inTenant(fn () => Order::query()->findOrFail($id));
        $this->assertSame([Order::CANCELLED, 'Not paid in time'], [$order->status, $order->cancel_reason]);
        $this->assertSame(1, $this->inTenant(fn () => Reservation::query()->where('order_id', $id)->whereNotNull('released_at')->count()));
        $this->assertSame('3', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.left'));
    }

    public function test_cancelling_releases_the_stock_and_needs_the_permission(): void
    {
        $this->stockUp('5');
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '2']]))->json('data.number'));
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));

        $this->api('POST', "ecommerce/orders/{$id}/cancel", ['reason' => 'Customer asked'], $cashier)->assertForbidden();
        $this->api('POST', "ecommerce/orders/{$id}/cancel", [], $this->manager)->assertUnprocessable();
        $this->api('POST', "ecommerce/orders/{$id}/cancel", ['reason' => 'Customer asked'], $this->manager)->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Customer asked');

        $this->assertNotNull($this->inTenant(fn () => Reservation::query()->where('order_id', $id)->sole()->released_at));
        $this->assertSame('5', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.left'), 'the stock is free again');
    }

    public function test_orders_are_scoped_by_location(): void
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->json('data.number'));
        // RBAC-04: a manager of branch B sees nothing of branch A's store.
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));

        $this->assertSame([], $this->api('GET', 'ecommerce/orders', [], $managerB)->assertOk()->json('data'));
        $this->api('GET', "ecommerce/orders/{$id}", [], $managerB)->assertNotFound();
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $managerB)->assertNotFound();

        $rows = $this->api('GET', 'ecommerce/orders?status=placed', [], $this->manager)->assertOk()->json('data');
        $this->assertSame([$id], array_column($rows, 'id'));

        // EXP-01: the list exports.
        $this->api('GET', 'ecommerce/orders?format=csv', [], $this->manager)->assertOk();
    }

    public function test_another_tenants_order_is_not_found(): void
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->json('data.number'));
        $other = $this->otherTenant();
        $this->inTenant(fn () => null);
        app(TenantContext::class)->run($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('ecommerce'));
        $headers = $this->bearer($this->tokenFor($other['user']));

        $this->json('GET', "/api/v1/ecommerce/orders/{$id}", [], $headers)->assertNotFound();
        $this->json('POST', "/api/v1/ecommerce/orders/{$id}/cancel", ['reason' => 'x'], $headers)->assertNotFound();
        $this->json('GET', "/api/v1/ecommerce/stores/{$this->shop->id}", [], $headers)->assertNotFound();
        $this->assertSame([], $this->json('GET', '/api/v1/ecommerce/orders', [], $headers)->assertOk()->json('data'));
    }

    public function test_the_module_is_closed_without_its_subscription(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('ecommerce'));

        $this->api('GET', 'ecommerce/orders')->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_a_phone_order_is_priced_and_placed_by_staff(): void
    {
        $quote = $this->api('POST', "ecommerce/stores/{$this->shop->id}/quote", $this->cart([[$this->serviceProduct, '2']]), $this->manager)->assertOk()->json('data');
        $this->assertSame('136000', $quote['totals']['total_minor']);

        $order = $this->api('POST', 'ecommerce/orders', [
            ...$this->cart([[$this->serviceProduct, '2']]), 'store_id' => $this->shop->id, 'channel' => 'phone', 'payment' => 'cod',
            'contact' => ['name' => 'Achieng', 'phone' => '0722000222'], 'address' => ['line' => 'Ring Road 4'], 'expected_total_minor' => '136000',
        ], $this->manager)->assertCreated()->json('data');

        $this->assertSame(['phone', 'cod', '+254722000222'], [$order['channel'], $order['payment_status'], $order['contact_phone']]);
        // ECOM-08: a phone order names no customer or party (only a verified sign-in does).
        $this->assertNull($order['party_id']);

        // Without ecommerce.order.create: refused.
        $riderHeaders = $this->riderUser;
        $this->api('POST', "ecommerce/stores/{$this->shop->id}/quote", $this->cart([[$this->serviceProduct, '1']]), $riderHeaders)->assertNotFound();
    }

    public function test_a_pickup_order_is_collected_at_the_counter(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']], ['fulfilment' => 'pickup', 'zone_id' => null, 'pickup_location_id' => $this->locationA->id]))->assertCreated()->json('data');
        $id = $this->orderId($placed['number']);
        $this->assertSame('116000', $placed['totals']['total_minor'], 'no delivery fee');

        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/ready", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/deliver", ['collected_minor' => '116000'], $this->manager)->assertOk()
            ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.payment_status', 'cod_collected');
        $this->assertNull($this->inTenant(fn () => Delivery::query()->where('order_id', $id)->first()));
    }

    public function test_a_delivered_order_is_returned_within_the_refund_limit(): void
    {
        $id = $this->deliveredCodOrder();
        $clerk = $this->inTenant(function () {
            $role = $this->role('Online clerk', ['ecommerce.order.view', 'ecommerce.order.manage']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::branch($this->branchA->id));

            return $user;
        });

        // RBAC-06: no max_refund_amount rule: not allowed.
        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'Damaged'], $clerk)->assertForbidden()->assertJsonPath('code', 'refund_above_limit');
        $order = $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'Damaged'])->assertOk()->json('data');
        $this->assertSame(['returned', 'refund_due'], [$order['status'], $order['payment_status']]);

        // RBAC-06: the refund keeps to the limit too: the Branch Manager template refunds up to 10,000 in the base
        // currency (owner decision A, 2026-10-16), so the manager refunds this order.
        $this->api('POST', "ecommerce/orders/{$id}/refund", [], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'refund_reference_required');
        $this->api('POST', "ecommerce/orders/{$id}/refund", ['reference' => 'CASH-RET-1'], $this->manager)->assertOk()->assertJsonPath('data.payment_status', 'refunded');
    }

    /** A COD delivery order delivered by the rider through the back office; its id. */
    protected function deliveredCodOrder(string $qty = '1'): string
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, $qty]]))->json('data.number'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();

        return $id;
    }
}
