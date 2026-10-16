<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\HostedCheckout;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent as History;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Shop\ShopUrls;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-15, ECOM-19: an order's online payment through the core payment
 * adapters (concept note 7.1): an M-Pesa STK push (core Daraja adapter) or
 * a card hosted checkout, as a core payment intent of reference type
 * `ecommerce.order` whose id is the order payment's own. The result comes
 * back through the core callback and PaymentIntentSettled, or when the
 * customer's page asks (check()), and is applied once under the order's
 * row lock: a repeated or late result changes nothing; money arriving
 * after a cancellation is marked for refund. Refunds go back through the
 * core payout (reference type `ecommerce.refund`).
 */
final class OrderPayments
{
    public const REFERENCE = 'ecommerce.order';

    public const REFUND_REFERENCE = 'ecommerce.refund';

    public function __construct(
        private readonly PaymentIntents $intents,
        private readonly PaymentProviderRegistry $providers,
        private readonly OrderNotices $notices,
    ) {}

    /**
     * Start (or start again) the push or checkout of an order's pending
     * online payment; after the placing transaction commits, since it
     * calls the provider.
     *
     * @return array{status: string, redirect_url: ?string, message: ?string}
     */
    public function start(Order $order, ?string $phone = null): array
    {
        $payment = Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card'])->whereIn('status', ['pending', 'failed'])->latest('created_at')->first();

        if ($payment === null || $order->status !== Order::PLACED || ! in_array($order->payment_status, ['pending', 'failed'], true)) {
            throw EcomError::make('payment_not_open', status: 409);
        }

        $method = PaymentMethod::query()->find($payment->payment_method_id);

        if ($method === null || ! $method->active) {
            throw EcomError::make('payment_method_unavailable');
        }

        // A retry is a new intent (the earlier one is final or abandoned); the order payment follows it.
        if ($payment->payment_intent_id !== null && PaymentIntent::query()->whereKey($payment->payment_intent_id)->exists()) {
            $payment = Tx::run(function () use ($payment, $order) {
                $payment->forceFill(['status' => 'failed'])->save();

                return Payment::create([
                    'order_id' => $order->id, 'kind' => $payment->kind, 'payment_method_id' => $payment->payment_method_id,
                    'currency' => $payment->currency, 'amount_minor' => $payment->amount_minor, 'status' => 'pending',
                ]);
            });
        }

        $provider = $this->providers->for($method);

        try {
            $intent = $this->intents->request($method, $provider, [
                'id' => $payment->id,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'payment_method_id' => $method->id,
                'provider' => (string) ($method->provider ?? $method->type),
                'purpose' => 'sale',
                'mode' => $payment->kind === 'card' ? 'checkout' : 'stk',
                'currency' => $payment->currency,
                'amount_minor' => $payment->amount_minor,
                'phone' => $phone ?? $order->contact_phone,
                'reference_type' => self::REFERENCE,
                'reference' => $order->id,
            ]);
        } catch (ApiException $e) {
            $this->failed($order, $payment, $e->errorCode);

            throw $e;
        }

        $payment->forceFill(['payment_intent_id' => $intent->id])->save();
        $this->settle($intent);
        $intent->refresh();

        return [
            'status' => $intent->status,
            'redirect_url' => $payment->kind === 'card' && $provider instanceof HostedCheckout ? ($intent->provider_data['redirect_url'] ?? null) : null,
            'message' => $intent->result_message,
        ];
    }

    /** The customer's page asks where the payment stands: the provider is asked, then the answer applied. */
    public function check(Order $order): void
    {
        foreach (Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card'])->where('status', 'pending')->whereNotNull('payment_intent_id')->get() as $payment) {
            $intent = PaymentIntent::query()->find($payment->payment_intent_id);

            if ($intent !== null) {
                $this->settle($this->intents->check($intent));
            }
        }
    }

    /** PaymentIntentSettled (and check()): bring a final intent back to its order, once. */
    public function settle(PaymentIntent $intent): void
    {
        if (in_array($intent->status, ['pending', 'unknown'], true)) {
            return;
        }

        if ($intent->reference_type === self::REFUND_REFERENCE) {
            $this->settleRefund($intent);

            return;
        }

        if ($intent->reference_type !== self::REFERENCE || $intent->purpose !== 'sale') {
            return;
        }

        Tx::run(function () use ($intent) {
            $order = Order::query()->whereKey($intent->reference)->lockForUpdate()->first();
            $payment = $order === null ? null : Payment::query()->where('order_id', $order->id)->whereKey($intent->id)->lockForUpdate()->first();

            if ($order === null || $payment === null || ! in_array($payment->status, ['pending', 'failed'], true)) {
                return;
            }

            $paid = $intent->status === 'succeeded' && (int) $intent->amount_minor === $payment->amount_minor && $intent->currency === $payment->currency;

            if (! $paid) {
                $this->failed($order, $payment, (string) ($intent->result_code ?? $intent->status));

                return;
            }

            $payment->forceFill(['status' => 'succeeded', 'provider_reference' => $intent->provider_receipt])->save();

            if ($order->status === Order::CANCELLED) {
                // Paid after the order was cancelled (a late result): the money goes back.
                $order->flag('paid_after_cancel', ['payment' => $payment->id]);
                $order->forceFill(['payment_status' => 'refund_due'])->save();

                return;
            }

            $order->forceFill(['payment_status' => 'paid', 'paid_at' => CarbonImmutable::now(), 'reserved_until' => null])->save();
            app(Reservations::class)->hold($order);
            History::create(['order_id' => $order->id, 'from' => $order->status, 'to' => $order->status, 'payment_status' => 'paid', 'at' => CarbonImmutable::now()]);
            OrderEvent::dispatch($order->tenant_id, OrderEvent::PAID, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor);
            $store = Store::query()->find($order->store_id);
            $this->notices->send(OrderNotices::PAID, $order, $store, ['tracking_url' => app(ShopUrls::class)->tracking($store, $order, (string) $order->tracking_token_secret)]);
        });
    }

    /**
     * ECOM-19: pay back an order's refund-due online payments through the
     * core payout; a payment without one (cash, or a provider that cannot
     * pay out) is marked refunded with the reference the user gives.
     */
    public function refund(Order $order, ?string $userId, ?string $reference): void
    {
        foreach (Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card', 'cod'])->whereIn('status', ['succeeded', 'collected'])->get() as $payment) {
            $original = $payment->payment_intent_id === null ? null : PaymentIntent::query()->find($payment->payment_intent_id);
            $method = PaymentMethod::query()->find($payment->payment_method_id);

            if ($original !== null && $method !== null && $original->status === 'succeeded') {
                $refundId = (string) ($payment->detail['refund_intent_id'] ?? Str::uuid7());
                $payment->forceFill(['detail' => [...($payment->detail ?? []), 'refund_intent_id' => $refundId]])->save();
                $this->settle($this->intents->payout($method, $original, [
                    'id' => $refundId, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency,
                    'reference_type' => self::REFUND_REFERENCE, 'reference' => $order->id, 'created_by' => $userId,
                ]));

                continue;
            }

            if ($reference === null || trim($reference) === '') {
                throw EcomError::make('refund_reference_required', field: 'reference');
            }

            $payment->forceFill(['status' => 'refunded', 'provider_reference' => $payment->provider_reference ?? mb_substr($reference, 0, 120)])->save();
        }

        $this->closeRefund($order, $reference);
    }

    private function settleRefund(PaymentIntent $intent): void
    {
        Tx::run(function () use ($intent) {
            $order = Order::query()->whereKey($intent->reference)->lockForUpdate()->first();

            if ($order === null) {
                return;
            }

            $payment = Payment::query()->where('order_id', $order->id)->whereRaw("detail->>'refund_intent_id' = ?", [$intent->id])->first();

            if ($payment === null) {
                return;
            }

            if ($intent->status === 'succeeded') {
                $payment->forceFill(['status' => 'refunded'])->save();
                $this->closeRefund($order, $intent->provider_receipt);
            } elseif (! in_array('payout_failed', array_column($order->flags ?? [], 'code'), true)) {
                $order->flag('payout_failed', ['payment' => $payment->id]);
                $order->save();
            }
        });
    }

    private function closeRefund(Order $order, ?string $reference): void
    {
        $open = Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card', 'cod'])->whereIn('status', ['succeeded', 'collected'])->exists();

        if (! $open && $order->payment_status === 'refund_due') {
            $order->forceFill(['payment_status' => 'refunded', 'refund_reference' => $reference === null ? $order->refund_reference : mb_substr($reference, 0, 120)])->save();
            History::create(['order_id' => $order->id, 'from' => $order->status, 'to' => $order->status, 'payment_status' => 'refunded', 'at' => CarbonImmutable::now()]);
        }
    }

    private function failed(Order $order, Payment $payment, string $code): void
    {
        Tx::run(function () use ($order, $payment, $code) {
            $payment->forceFill(['status' => 'failed', 'detail' => [...($payment->detail ?? []), 'result' => $code]])->save();
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($order !== null && $order->payment_status === 'pending') {
                $order->forceFill(['payment_status' => 'failed'])->save();
            }
        });
    }
}
