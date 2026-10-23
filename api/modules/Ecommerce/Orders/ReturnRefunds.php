<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Identity\Models\User;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent as History;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-42, ECOM-19: a return's money back through the same paths as an
 * order's refund: the core payout on the online payment it came from
 * (reference type `ecommerce.return_refund`, applied once when the
 * provider settles), else by hand (cash) with the reference the user
 * types. The amount goes back to the order's tenders in order (M-Pesa,
 * card, cash: Returns::allocation, net of what earlier returns took); a
 * part paid by points or a gift card is not paid out in money (flagged
 * `loyalty_share`, decision 16). Recorded in the order's history and
 * audited (AUD-01).
 *
 * Review H1 (RBAC-06): the order's refunds in all (refunded and due, this
 * one included) must be within the user's `max_refund_amount`, unless the
 * return was approved; above it the refund goes for approval (ReturnType)
 * and waits (`approval_status` pending) instead of being refused.
 */
final class ReturnRefunds
{
    public const REFERENCE = 'ecommerce.return_refund';

    public function __construct(private readonly PaymentIntents $intents, private readonly OrderFlow $flow, private readonly WorkflowEngine $engine) {}

    public function refund(OrderReturn $return, User $by, ?string $reference): OrderReturn
    {
        $intents = Tx::run(function () use ($return, $by, $reference) {
            $order = Order::query()->whereKey($return->order_id)->lockForUpdate()->firstOrFail();
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->refund_status !== 'due') {
                throw EcomError::make('refund_not_due', status: 409);
            }

            if ($return->approval_status === 'pending' || $return->committed_at === null) {
                throw EcomError::make('return_awaiting_approval', status: 409);
            }

            if ($return->approval_status !== 'approved' && ! $this->flow->withinRefundLimit($order, $by, Returns::refundsOf($order))) {
                $return->forceFill(['approval_status' => 'pending', 'approval_requested_by' => $by->id])->save();
                $this->engine->start(ReturnType::KEY, $return->id, $by);

                return [];
            }

            $detail = array_values(array_filter($return->refund_detail ?? [], fn (array $entry) => $entry['status'] !== 'failed'));
            $left = $return->refund_minor - (int) array_sum(array_column($detail, 'amount_minor'));
            $reference = $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 120);
            $online = [];
            $byHand = [];

            // The refund is the money side of what came back: the first tenders of its allocation, net of an exchange.
            foreach (Returns::allocation($order, $return) as $part) {
                if ($left <= 0) {
                    break;
                }

                $payment = $part['payment'];
                $already = (int) array_sum(array_column(array_filter($detail, fn (array $e) => $e['payment_id'] === $payment->id), 'amount_minor'));
                $take = min($left, $part['amount_minor'] - $already);

                if ($take <= 0 || ! in_array($payment->kind, ['mpesa', 'card', 'cod'], true)) {
                    continue;
                }

                $intent = $payment->payment_intent_id === null ? null : PaymentIntent::query()->find($payment->payment_intent_id);

                if ($intent !== null && $intent->status === 'succeeded' && in_array($payment->kind, ['mpesa', 'card'], true)) {
                    $online[] = ['payment_id' => $payment->id, 'kind' => $payment->kind, 'amount_minor' => $take, 'intent_id' => (string) Str::uuid7(), 'status' => 'pending'];
                } else {
                    $byHand[] = ['payment_id' => $payment->id, 'kind' => $payment->kind, 'amount_minor' => $take, 'status' => 'refunded'];
                }

                $left -= $take;
            }

            if ($byHand !== [] && $reference === null) {
                throw EcomError::make('refund_reference_required', field: 'reference');
            }

            if ($left > 0 && ! in_array('loyalty_share', array_column($return->flags ?? [], 'code'), true)) {
                $return->flag('loyalty_share', ['amount_minor' => (string) $left]);
            }

            $return->refund_detail = [...($return->refund_detail ?? []), ...$online, ...$byHand];
            $return->refund_reference = $reference ?? $return->refund_reference;
            $return->save();
            $this->close($order, $return, $by);

            return $online;
        });

        // The provider is called once the refund's entries are stored (a retry reuses their ids).
        foreach ($intents as $entry) {
            $this->payout($return, $entry, $by);
        }

        return $return->refresh();
    }

    /** PaymentIntentSettled for a return's payout: the entry is applied once. */
    public function settle(PaymentIntent $intent): void
    {
        if ($intent->reference_type !== self::REFERENCE || in_array($intent->status, ['pending', 'unknown'], true)) {
            return;
        }

        Tx::run(function () use ($intent) {
            $return = OrderReturn::query()->whereKey($intent->reference)->first();
            $order = $return === null ? null : Order::query()->whereKey($return->order_id)->lockForUpdate()->first();

            if ($order === null) {
                return;
            }

            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $detail = $return->refund_detail ?? [];
            $changed = false;

            foreach ($detail as $i => $entry) {
                if (($entry['intent_id'] ?? null) === $intent->id && $entry['status'] === 'pending') {
                    $detail[$i]['status'] = $intent->status === 'succeeded' ? 'refunded' : 'failed';
                    $changed = true;

                    if ($intent->status !== 'succeeded') {
                        $return->flag('payout_failed', ['intent_id' => $intent->id]);
                    }
                }
            }

            if ($changed) {
                $return->refund_detail = $detail;
                $return->refund_reference = $return->refund_reference ?? $intent->provider_receipt;
                $return->save();
                $this->close($order, $return, null);
            }
        });
    }

    /** @param array{payment_id: string, amount_minor: int, intent_id: string} $entry */
    private function payout(OrderReturn $return, array $entry, User $by): void
    {
        $payment = Payment::query()->findOrFail($entry['payment_id']);
        $original = PaymentIntent::query()->findOrFail($payment->payment_intent_id);
        $method = PaymentMethod::query()->findOrFail($payment->payment_method_id);

        $this->settle($this->intents->payout($method, $original, [
            'id' => $entry['intent_id'], 'amount_minor' => $entry['amount_minor'], 'currency' => $payment->currency,
            'reference_type' => self::REFERENCE, 'reference' => $return->id, 'created_by' => $by->id,
        ]));
    }

    /** Refunded once every entry is paid back (and nothing is left but a loyalty share). */
    private function close(Order $order, OrderReturn $return, ?User $by): void
    {
        $detail = $return->refund_detail ?? [];
        $paid = (int) array_sum(array_column(array_filter($detail, fn (array $e) => $e['status'] === 'refunded'), 'amount_minor'));
        $open = array_filter($detail, fn (array $e) => $e['status'] === 'pending') !== [];
        $loyalty = (int) collect($return->flags ?? [])->where('code', 'loyalty_share')->sum(fn (array $f) => (int) ($f['detail']['amount_minor'] ?? 0));

        if ($open || $return->refund_status !== 'due' || $paid + $loyalty < $return->refund_minor) {
            return;
        }

        $return->forceFill(['refund_status' => 'refunded', 'refunded_at' => CarbonImmutable::now(), 'refunded_by' => $by?->id ?? $return->refunded_by])->save();
        History::create(['order_id' => $order->id, 'from' => $order->status, 'to' => $order->status, 'payment_status' => $order->payment_status,
            'note' => mb_substr(__('ecommerce.returns.refunded', ['number' => $return->number]), 0, 500), 'user_id' => $by?->id, 'at' => CarbonImmutable::now()]);
    }
}
