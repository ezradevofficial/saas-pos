<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Identity\Models\User;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Events\OrderFulfilled;
use Modules\Ecommerce\Events\OrderReturned;
use Modules\Ecommerce\Integrations\Postings;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent as History;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Shop\ShopUrls;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;
use Modules\Loyalty\Contracts\OnlineLoyalty;

/**
 * ECOM-16 to ECOM-19: an order's status flow, each step under the order's
 * row lock, recorded in its history and audited (the order model):
 *
 * placed → accepted (paid or cash on delivery) → picking → ready →
 * out_for_delivery (a delivery handed to its rider) or delivered (a pickup
 * collected); out_for_delivery → delivered (the rider's proof, or staff);
 * cancelled before hand-over; returned after it (a failed delivery, or a
 * return of a delivered order).
 *
 * Hand-over consumes the reservations and raises OrderFulfilled
 * (Inventory). Delivery raises OrderEvent `completed` (Loyalty, fiscal)
 * and the `ecommerce.order` posting (Accounting). A return raises
 * OrderReturned (Inventory) and, for a delivered order, the
 * `ecommerce.return` posting.
 */
final class OrderFlow
{
    public function __construct(
        private readonly Reservations $reservations,
        private readonly Postings $postings,
        private readonly OrderNotices $notices,
        private readonly ShopUrls $urls,
        private readonly LimitRules $limits,
        private readonly ScopeResolver $resolver,
    ) {}

    public function accept(Order $order, ?User $by): Order
    {
        return $this->step($order, [Order::PLACED], Order::ACCEPTED, $by, null, function (Order $order) {
            if (! in_array($order->payment_status, ['paid', 'cod'], true)) {
                throw EcomError::make('order_unpaid');
            }

            $order->accepted_at = CarbonImmutable::now();

            if ($order->fulfilment === 'delivery' && ! Delivery::query()->where('order_id', $order->id)->exists()) {
                Delivery::create([
                    'order_id' => $order->id, 'company_id' => $order->company_id, 'location_id' => $order->location_id,
                    'status' => Delivery::UNASSIGNED, 'currency' => $order->currency,
                    'cod_due_minor' => $order->payment_status === 'cod' ? $order->due_minor : 0,
                ]);
            }
        }, OrderNotices::ACCEPTED);
    }

    public function pick(Order $order, ?User $by): Order
    {
        return $this->step($order, [Order::ACCEPTED], Order::PICKING, $by, null, fn (Order $order) => $order->picking_at = CarbonImmutable::now());
    }

    public function ready(Order $order, ?User $by): Order
    {
        return $this->step($order, [Order::ACCEPTED, Order::PICKING], Order::READY, $by, null, fn (Order $order) => $order->ready_at = CarbonImmutable::now(),
            $order->fulfilment === 'pickup' ? OrderNotices::READY : null);
    }

    /**
     * ECOM-18: a delivery order handed to its rider (staff, or the rider's
     * `picked_up`): stock leaves, a delivery code goes to the customer.
     */
    public function dispatch(Order $order, ?User $by, ?CarbonImmutable $at = null): Order
    {
        if ($order->fulfilment !== 'delivery') {
            throw EcomError::make('not_a_delivery');
        }

        $code = null;
        $order = $this->step($order, [Order::ACCEPTED, Order::PICKING, Order::READY], Order::OUT_FOR_DELIVERY, $by, null, function (Order $order) use ($by, $at, &$code) {
            $delivery = Delivery::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($delivery === null || $delivery->rider_id === null) {
                throw EcomError::make('rider_required');
            }

            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $order->dispatched_at = $at ?? CarbonImmutable::now();
            $order->delivery_code_hash = self::codeHash($order->id, $code);

            if ($delivery->status === Delivery::ASSIGNED) {
                $delivery->forceFill(['status' => Delivery::PICKED_UP, 'picked_up_at' => $order->dispatched_at])->save();
            }

            $this->handOver($order, $by);
        });

        $this->notices->send(OrderNotices::DISPATCHED, $order, $this->store($order), ['tracking_url' => $this->tracking($order), 'delivery_code' => (string) $code]);

        return $order;
    }

    /**
     * Delivered: a pickup collected at the counter (hand-over and delivery
     * at once; cash on delivery collected by the user), or a delivery
     * confirmed by its rider or by staff.
     */
    public function deliver(Order $order, ?User $by, ?CarbonImmutable $at = null, ?int $collected = null): Order
    {
        $from = $order->fulfilment === 'pickup' ? [Order::ACCEPTED, Order::PICKING, Order::READY] : [Order::OUT_FOR_DELIVERY];

        return $this->step($order, $from, Order::DELIVERED, $by, null, function (Order $order) use ($by, $at, $collected) {
            $order->delivered_at = $at ?? CarbonImmutable::now();

            if ($order->fulfilment === 'pickup') {
                $this->handOver($order, $by);
            } else {
                // Confirmed by staff (the rider's own proof sets it first).
                Delivery::query()->where('order_id', $order->id)->where('status', Delivery::PICKED_UP)->lockForUpdate()->get()
                    ->each(fn (Delivery $d) => $d->forceFill(['status' => Delivery::DELIVERED, 'delivered_at' => $order->delivered_at])->save());
            }

            if ($order->payment_status === 'cod') {
                $this->collect($order, $by, $collected ?? $order->due_minor);
            }

            OrderEvent::dispatch($order->tenant_id, OrderEvent::COMPLETED, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor);
            $this->postings->order($order);
        }, OrderNotices::DELIVERED);
    }

    public function cancel(Order $order, ?User $by, string $reason): Order
    {
        return $this->step($order, Order::OPEN, Order::CANCELLED, $by, $reason, function (Order $order) use ($by, $reason) {
            $order->forceFill(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $by?->id, 'cancel_reason' => mb_substr($reason, 0, 500), 'reserved_until' => null]);
            $this->reservations->release($order);
            $this->giveBackLoyalty($order);

            if (Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card'])->where('status', 'succeeded')->exists()) {
                $order->payment_status = 'refund_due';
            }

            Delivery::query()->where('order_id', $order->id)->whereIn('status', [Delivery::UNASSIGNED, Delivery::ASSIGNED])
                ->get()->each(fn (Delivery $d) => $d->forceFill(['status' => Delivery::FAILED, 'failed_at' => CarbonImmutable::now(), 'reason' => mb_substr($reason, 0, 500)])->save());
        }, OrderNotices::CANCELLED);
    }

    /**
     * ECOM-18, ECOM-19: an order that left comes back: a failed delivery
     * (nothing was sold) or a return of a delivered order (refund due on
     * what was paid, the `ecommerce.return` posting, the fiscal credit
     * note, Loyalty reversed). One return per order (decision 10).
     */
    public function return(Order $order, ?User $by, string $reason, bool $fee = false): Order
    {
        $delivered = $order->status === Order::DELIVERED;

        if ($delivered && $by !== null) {
            $this->assertRefundLimit($order, $by, $fee);
        }

        return $this->step($order, [Order::OUT_FOR_DELIVERY, Order::DELIVERED], Order::RETURNED, $by, $reason, function (Order $order) use ($by, $reason, $fee, $delivered) {
            $order->forceFill(['returned_at' => CarbonImmutable::now(), 'returned_by' => $by?->id, 'return_reason' => mb_substr($reason, 0, 500), 'return_fee' => $delivered && $fee]);
            $this->giveBackLoyalty($order);
            $paid = Payment::query()->where('order_id', $order->id)->whereIn('kind', ['mpesa', 'card', 'cod'])->whereIn('status', ['succeeded', 'collected'])->exists();

            if ($paid) {
                $order->payment_status = 'refund_due';
            } elseif ($order->payment_status === 'cod') {
                // A failed cash on delivery: nothing was collected, nothing is owed.
                Payment::query()->where('order_id', $order->id)->where('kind', 'cod')->where('status', 'pending')->get()
                    ->each(fn (Payment $p) => $p->forceFill(['status' => 'reversed'])->save());
            }

            $delivery = Delivery::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($delivery !== null && $delivery->status !== Delivery::DELIVERED && $delivery->status !== Delivery::FAILED) {
                $delivery->forceFill(['status' => Delivery::FAILED, 'failed_at' => CarbonImmutable::now(), 'reason' => mb_substr($reason, 0, 500)])->save();
            }

            OrderReturned::dispatch($order->tenant_id, $order->id, $order->number, $order->company_id, $order->branch_id, $order->location_id,
                CarbonImmutable::now()->toIso8601String(), $by?->id, $delivered);

            if ($delivered) {
                $this->postings->return($order);
            }
        });
    }

    /** ECOM-30: cash collected on delivery or at pickup (a short or over amount is flagged on the delivery). */
    public function collect(Order $order, ?User $by, int $collected): void
    {
        $payment = Payment::query()->where('order_id', $order->id)->where('kind', 'cod')->where('status', 'pending')->lockForUpdate()->first();

        if ($payment === null) {
            return;
        }

        $payment->forceFill(['status' => 'collected', 'collected_by' => $by?->id, 'collected_at' => CarbonImmutable::now(),
            'detail' => [...($payment->detail ?? []), 'collected_minor' => (string) $collected]])->save();
        $order->payment_status = 'cod_collected';
        $delivery = Delivery::query()->where('order_id', $order->id)->lockForUpdate()->first();

        if ($delivery !== null) {
            $flags = $delivery->flags ?? [];

            if ($collected !== $payment->amount_minor) {
                $flags[] = ['code' => $collected < $payment->amount_minor ? 'cod_short' : 'cod_over', 'detail' => ['due' => (string) $payment->amount_minor, 'collected' => (string) $collected]];
            }

            $delivery->forceFill(['cod_collected_minor' => $collected, 'collected_at' => CarbonImmutable::now(), 'flags' => $flags])->save();
        }
    }

    public static function codeHash(string $orderId, string $code): string
    {
        return hash_hmac('sha256', $orderId.'|'.$code, (string) config('app.key'));
    }

    /** RBAC-06: a return of a delivered order refunds within the user's `max_refund_amount` (base currency, major units); an Owner has no limit. */
    private function assertRefundLimit(Order $order, User $by, bool $fee): void
    {
        $scope = Scope::location($order->location_id);
        $roles = $this->resolver->roleIds($by, $scope);

        if ($roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists()) {
            return;
        }

        $max = $this->limits->max($by, 'max_refund_amount', $scope);
        $total = $order->total_minor - ($fee ? 0 : $order->delivery_fee_minor + $order->delivery_tax_minor);
        $base = $order->total_minor === 0 ? BigDecimal::zero() : BigDecimal::of($order->base_total_minor)->multipliedBy($total)->dividedBy($order->total_minor, 0, RoundingMode::HalfUp);
        $decimals = app(CurrencyDecimals::class)->for($order->base_currency);

        if ($max === null || $base->withPointMovedLeft($decimals)->isGreaterThan($max)) {
            throw EcomError::make('refund_above_limit', status: 403);
        }
    }

    private function handOver(Order $order, ?User $by): void
    {
        $this->reservations->consume($order);
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get();
        OrderFulfilled::dispatch($order->tenant_id, $order->id, $order->number, $order->company_id, $order->branch_id, $order->location_id,
            ($order->dispatched_at ?? $order->delivered_at ?? CarbonImmutable::now())->toIso8601String(), $by?->id,
            $lines->map(fn (OrderLine $line) => [
                'line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => (string) $line->qty, 'qty_base' => (string) $line->qty_base,
            ])->values()->all());
    }

    private function giveBackLoyalty(Order $order): void
    {
        $redeemed = Payment::query()->where('order_id', $order->id)->whereIn('kind', ['points', 'gift_card'])->where('status', 'succeeded')->get();

        if ($redeemed->isEmpty() || ! class_exists(OnlineLoyalty::class)) {
            return;
        }

        app(OnlineLoyalty::class)->giveBack($order->id);
        $redeemed->each(fn (Payment $p) => $p->forceFill(['status' => 'reversed'])->save());
    }

    /**
     * One status change under the order's lock.
     *
     * @param  list<string>  $from
     */
    private function step(Order $order, array $from, string $to, ?User $by, ?string $note, ?callable $change = null, ?string $notice = null): Order
    {
        $order = Tx::run(function () use ($order, $from, $to, $by, $note, $change) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, $from, true)) {
                throw EcomError::make('status_invalid', ['status' => __("ecommerce.statuses.{$order->status}")], status: 409);
            }

            $was = $order->status;
            $order->status = $to;

            if ($change !== null) {
                $change($order);
            }

            $order->save();
            History::create(['order_id' => $order->id, 'from' => $was, 'to' => $to, 'payment_status' => $order->payment_status, 'note' => $note === null ? null : mb_substr($note, 0, 500),
                'user_id' => $by?->id, 'at' => CarbonImmutable::now()]);
            OrderEvent::dispatch($order->tenant_id, OrderEvent::STATUS_CHANGED, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor, $was, $to);

            return $order;
        });

        if ($notice !== null) {
            $this->notices->send($notice, $order, $this->store($order), ['tracking_url' => $this->tracking($order)]);
        }

        return $order;
    }

    private function store(Order $order): Store
    {
        return Store::query()->findOrFail($order->store_id);
    }

    private function tracking(Order $order): string
    {
        return $this->urls->tracking($this->store($order), $order, (string) $order->tracking_token_secret);
    }
}
