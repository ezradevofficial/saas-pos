<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Http\ApiException;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Payments\ProviderResult;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Orders\OrderFlow;
use Modules\Ecommerce\Orders\OrderPayments;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-14, ECOM-15, ECOM-19, RBAC-06: an order is paid once. A retry waits
// for the earlier push; money beyond the order (a second settlement, a
// wrong amount) is held for refund and never posted; a payment after the
// reservation expired re-checks the stock; refunds keep to the user's limit;
// the expiry never cancels an order paid meanwhile.
class PaymentSafetyTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_retry_is_refused_while_the_push_is_pending(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa')->assertCreated()->json('data');
        $this->assertSame('pending', $placed['payment']['status']);

        $this->shopCall('POST', "duka/orders/{$placed['number']}/pay", ['t' => $placed['tracking_token']])->assertStatus(409)->assertJsonPath('code', 'payment_in_progress');
        $this->assertSame(1, $this->inTenant(fn () => PaymentIntent::query()->where('reference_type', 'ecommerce.order')->count()));
    }

    public function test_a_second_settlement_is_held_for_refund_and_never_posted(): void
    {
        $this->withAccounting();
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa')->json('data');
        $id = $this->orderId($placed['number']);
        $this->inTenant(function () use ($id) {
            $first = PaymentIntent::query()->where('reference', $id)->sole();
            app(OrderPayments::class)->settle(app(PaymentIntents::class)->apply($first, ProviderResult::succeeded('QJK3ECOM01', ['result_code' => '0'], 136000)));
            // A second push for the same order (a duplicate the provider settled too).
            $this->settleExtra($id, 136000, 'QJK3ECOM02');
            // And one for the wrong amount.
            $this->settleExtra($id, 100000, 'QJK3ECOM03', intentAmount: 136000);
        });

        $order = $this->inTenant(fn () => Order::query()->findOrFail($id));
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(['overpaid', 'amount_mismatch'], array_column($order->flags, 'code'));
        $this->assertSame(['succeeded', 'refund_due', 'refund_due'], $this->inTenant(fn () => Payment::query()->where('order_id', $id)->orderBy('created_at')->orderBy('id')->pluck('status')->all()));
        $this->assertSame(1, $this->inTenant(fn () => OrderEvent::query()->where('order_id', $id)->where('payment_status', 'paid')->count()), 'paid once');

        // Delivered: one journal, balanced, the tender the order's total only.
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();
        $ledger = $this->ledger('ecommerce.order');
        $this->assertSame(136000, array_sum(array_column($ledger, 0)));

        // The excess goes back through the refund, and still posts nothing.
        $this->api('POST', "ecommerce/orders/{$id}/refund", ['reference' => 'EXCESS-1'])->assertOk();
        $this->assertSame(['succeeded', 'refunded_excess', 'refunded_excess'], $this->inTenant(fn () => Payment::query()->where('order_id', $id)->orderBy('created_at')->orderBy('id')->pluck('status')->all()));
        $this->assertSame($ledger, $this->ledger('ecommerce.order'));
    }

    public function test_paying_after_the_reservation_expired_is_refused(): void
    {
        // Phone ending 222: the push is declined, so the order waits for a retry.
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Njeri', 'phone' => '0712000222']])->json('data');
        $this->shopCall('POST', "duka/orders/{$placed['number']}/check", ['t' => $placed['tracking_token']])->assertJsonPath('data.payment_status', 'failed');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));

        $this->shopCall('POST', "duka/orders/{$placed['number']}/pay", ['t' => $placed['tracking_token'], 'phone' => '0712000999'])->assertStatus(409)->assertJsonPath('code', 'payment_expired');
    }

    public function test_a_late_payment_rechecks_the_stock_and_never_oversells(): void
    {
        $this->stockUp('1');
        // Phone ending 111: the push never answers.
        $late = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Otieno', 'phone' => '0712000111']])->json('data');
        $id = $this->orderId($late['number']);

        // The reservation lapsed and another customer bought the last unit.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated();

        $this->inTenant(function () use ($id) {
            $intent = PaymentIntent::query()->where('reference', $id)->sole();
            app(OrderPayments::class)->settle(app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3LATE01', ['result_code' => '0'], 136000)));
        });

        $order = $this->inTenant(fn () => Order::query()->findOrFail($id));
        $this->assertSame('refund_due', $order->payment_status);
        $this->assertContains('stock_short', array_column($order->flags, 'code'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'order_unpaid');
    }

    public function test_a_late_payment_with_stock_left_is_paid_and_holds_it(): void
    {
        $this->stockUp('2');
        $late = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Otieno', 'phone' => '0712000111']])->json('data');
        $id = $this->orderId($late['number']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));

        $this->inTenant(function () use ($id) {
            $intent = PaymentIntent::query()->where('reference', $id)->sole();
            app(OrderPayments::class)->settle(app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3LATE02', ['result_code' => '0'], 136000)));
        });

        $this->assertSame('paid', $this->inTenant(fn () => Order::query()->findOrFail($id)->payment_status));
        $this->assertSame('1', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.left'));
    }

    public function test_a_refund_keeps_to_the_users_refund_limit(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa')->json('data');
        $id = $this->orderId($placed['number']);
        $this->shopCall('POST', "duka/orders/{$placed['number']}/check", ['t' => $placed['tracking_token']])->assertJsonPath('data.payment_status', 'paid');
        $this->api('POST', "ecommerce/orders/{$id}/cancel", ['reason' => 'Out of stock'], $this->manager)->assertOk()->assertJsonPath('data.payment_status', 'refund_due');

        $clerk = $this->inTenant(function () {
            $role = $this->role('Refund clerk', ['ecommerce.order.view', 'ecommerce.order.refund']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::branch($this->branchA->id));

            return $user;
        });

        // RBAC-06: no max_refund_amount rule: not allowed.
        $this->api('POST', "ecommerce/orders/{$id}/refund", [], $clerk)->assertForbidden()->assertJsonPath('code', 'refund_above_limit');
        $this->assertSame('refund_due', $this->inTenant(fn () => Order::query()->findOrFail($id)->payment_status));
        $this->api('POST', "ecommerce/orders/{$id}/refund", [])->assertOk()->assertJsonPath('data.payment_status', 'refunded');
    }

    public function test_the_expiry_never_cancels_an_order_paid_meanwhile(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Otieno', 'phone' => '0712000111']])->json('data');
        $id = $this->orderId($placed['number']);
        // The expiry read the order while it was unpaid…
        $stale = $this->inTenant(fn () => Order::query()->findOrFail($id));

        // …and the payment landed before it took the lock.
        $this->inTenant(function () use ($id) {
            $intent = PaymentIntent::query()->where('reference', $id)->sole();
            app(OrderPayments::class)->settle(app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3RACE01', ['result_code' => '0'], 136000)));
        });

        try {
            $this->inTenant(fn () => app(OrderFlow::class)->cancel($stale, null, 'Not paid in time', unpaidOnly: true));
            $this->fail('the expiry cancelled a paid order');
        } catch (ApiException $e) {
            $this->assertSame('payment_settled', $e->errorCode);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        Artisan::call('ecommerce:expire');
        $this->assertSame([Order::PLACED, 'paid'], $this->inTenant(fn () => [Order::query()->findOrFail($id)->status, Order::query()->findOrFail($id)->payment_status]));
    }

    /** A further payment row and intent for order $id, settled as paid by the provider. */
    private function settleExtra(string $id, int $paymentAmount, string $receipt, ?int $intentAmount = null): void
    {
        $payment = Payment::create(['order_id' => $id, 'kind' => 'mpesa', 'payment_method_id' => $this->mpesa->id, 'currency' => 'KES', 'amount_minor' => $paymentAmount, 'status' => 'pending']);
        $order = Order::query()->findOrFail($id);
        $intent = app(PaymentIntents::class)->request($this->mpesa, app(PaymentProviderRegistry::class)->for($this->mpesa), [
            'id' => $payment->id, 'company_id' => $order->company_id, 'location_id' => $order->location_id, 'payment_method_id' => $this->mpesa->id, 'provider' => 'mpesa_ke',
            'purpose' => 'sale', 'mode' => 'stk', 'currency' => 'KES', 'amount_minor' => $intentAmount ?? $paymentAmount, 'phone' => '+254712345678',
            'reference_type' => OrderPayments::REFERENCE, 'reference' => $id,
        ]);
        $payment->forceFill(['payment_intent_id' => $intent->id])->save();
        app(OrderPayments::class)->settle(app(PaymentIntents::class)->apply($intent->refresh(), ProviderResult::succeeded($receipt, ['result_code' => '0'], $intentAmount ?? $paymentAmount)));
    }
}
