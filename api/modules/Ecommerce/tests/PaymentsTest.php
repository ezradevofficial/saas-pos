<?php

namespace Modules\Ecommerce\Tests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\ProviderResult;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Orders\OrderPayments;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-15, ECOM-19: online payments through the core adapters (the fake
// driver stands in for Daraja): the push, the result applied once however
// often it arrives, a retry, a payment after cancellation, a payout refund.
class PaymentsTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_an_mpesa_push_is_started_and_its_result_applied_once(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa')->assertCreated()->json('data');
        $this->assertSame(['pending', 'pending'], [$placed['payment_status'], $placed['payment']['status']]);
        $id = $this->orderId($placed['number']);
        $intent = $this->inTenant(fn () => PaymentIntent::query()->where('reference_type', 'ecommerce.order')->where('reference', $id)->sole());
        $this->assertSame(['stk', 136000, 'KES'], [$intent->mode, (int) $intent->amount_minor, $intent->currency]);

        // The provider's result (as the callback applies it), then the same result again and the event twice.
        $this->inTenant(function () use ($intent) {
            $applied = app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3ECOM01', ['result_code' => '0'], 136000));
            app(OrderPayments::class)->settle($applied);
            app(OrderPayments::class)->settle($applied->refresh());
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'ecommerce.order', $intent->reference));
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'ecommerce.order', $intent->reference));
        });

        $order = $this->inTenant(fn () => Order::query()->findOrFail($id));
        $this->assertSame(['paid', null], [$order->payment_status, $order->reserved_until]);
        $this->assertSame(1, $this->inTenant(fn () => OrderEvent::query()->where('order_id', $id)->where('payment_status', 'paid')->count()), 'paid once');
        $this->assertSame('QJK3ECOM01', $this->inTenant(fn () => Payment::query()->where('order_id', $id)->sole()->provider_reference));

        $tracked = $this->shopCall('GET', "duka/orders/{$placed['number']}?t={$placed['tracking_token']}")->json('data');
        $this->assertSame('paid', $tracked['payment_status']);
    }

    public function test_the_waiting_page_asks_the_provider_and_a_failed_push_can_be_retried(): void
    {
        // Phone ending 222: the fake customer declines.
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa', extra: ['contact' => ['name' => 'Njeri', 'phone' => '0712000222']])->json('data');
        $track = "duka/orders/{$placed['number']}";

        $this->shopCall('POST', "{$track}/check", ['t' => $placed['tracking_token']])->assertOk()->assertJsonPath('data.payment_status', 'failed');

        // Retried with another phone: pending, then paid when checked.
        $this->shopCall('POST', "{$track}/pay", ['t' => $placed['tracking_token'], 'phone' => '0712000999'])->assertOk()->assertJsonPath('data.payment.status', 'pending');
        $this->shopCall('POST', "{$track}/check", ['t' => $placed['tracking_token']])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(['failed', 'succeeded'], $this->inTenant(fn () => Payment::query()->where('order_id', $this->orderId($placed['number']))->orderBy('created_at')->orderBy('id')->pluck('status')->all()));

        // Paid: nothing more to pay.
        $this->shopCall('POST', "{$track}/pay", ['t' => $placed['tracking_token']])->assertStatus(409)->assertJsonPath('code', 'payment_not_open');
    }

    public function test_money_after_a_cancellation_is_refunded_through_the_payout(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'mpesa')->json('data');
        $id = $this->orderId($placed['number']);
        $this->api('POST', "ecommerce/orders/{$id}/cancel", ['reason' => 'Out of stock'], $this->manager)->assertOk();

        // The push is paid after all (a late result): the order is marked for refund, not paid.
        $this->shopCall('POST', "duka/orders/{$placed['number']}/check", ['t' => $placed['tracking_token']])->assertOk()->assertJsonPath('data.payment_status', 'refund_due');

        $cashier = $this->riderUser;
        $this->api('POST', "ecommerce/orders/{$id}/refund", [], $cashier)->assertNotFound();
        $this->api('POST', "ecommerce/orders/{$id}/refund")->assertOk()->assertJsonPath('data.payment_status', 'refunded');

        $payout = $this->inTenant(fn () => PaymentIntent::query()->where('reference_type', 'ecommerce.refund')->where('reference', $id)->sole());
        $this->assertSame(['refund', 'payout', 'succeeded', 136000], [$payout->purpose, $payout->mode, $payout->status, (int) $payout->amount_minor]);
        $this->api('POST', "ecommerce/orders/{$id}/refund")->assertStatus(409)->assertJsonPath('code', 'refund_not_due');
    }

    public function test_a_card_order_gets_the_checkout_page(): void
    {
        $card = $this->inTenant(function () {
            $method = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'card', 'name' => 'Card', 'provider' => 'card_test', 'currency' => 'KES', 'position' => 3, 'active' => true]);
            $this->shop->forceFill(['payment_methods' => [...$this->shop->payment_methods, ['kind' => 'card', 'payment_method_id' => $method->id]]])->save();

            return $method;
        });
        config(['payments.drivers.card_test' => 'fake']);

        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'card')->assertCreated()->json('data');
        $this->assertSame('pending', $placed['payment']['status']);
        $this->assertNotNull($placed['payment']['redirect_url']);
        $this->assertSame('checkout', $this->inTenant(fn () => PaymentIntent::query()->where('payment_method_id', $card->id)->sole()->mode));
    }

    public function test_a_payment_method_the_store_does_not_offer_is_refused(): void
    {
        $this->placeOrder($this->cart([[$this->widgetProduct, '1']]), 'card')->assertUnprocessable()->assertJsonPath('code', 'payment_unavailable');
        $this->assertSame(0, $this->inTenant(fn () => Order::query()->count()));
    }
}
