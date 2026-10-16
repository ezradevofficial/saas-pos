<?php

namespace Modules\Ecommerce\Integrations;

use App\Core\MasterData\Items\Item;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Events\EcommercePosting;
use Modules\Ecommerce\Models\Cashup;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Store;

/**
 * ECOM-32: what a delivered order, a return or a cash-up posts, as fields
 * of the posting rules (docs/modules/ecommerce.md), in the document's
 * currency at its own rate (CUR-04). Raised as EcommercePosting after the
 * document commits; Accounting posts it only while it is active.
 *
 * `ecommerce.order`: `tender.{payment method}` (what was paid online, by
 * points or by gift card) and `cod` (cash on delivery, to the clearing
 * account until the rider's cash-up) on the debit side;
 * `revenue.{item category}` (`revenue.none` without one: the lines' net
 * plus their promotion's net share), `delivery` (the fee net of tax) and
 * `tax.{tax code}` (lines and fee) on the credit side, `promotion` (the
 * promotions' net share) on the debit side. So Σ tender + cod = total =
 * Σ revenue − promotion + delivery + Σ tax, exactly.
 *
 * `ecommerce.return`: the same fields for what goes back (the fee only
 * when refunded), its tenders the payments refunded in order (online, cash,
 * gift card, points); posted with the order rule's sides swapped unless the
 * company has its own rule.
 *
 * `ecommerce.cod_cashup`: `cash` (counted) and `shortage` on the debit
 * side, `cod` (expected) and `overage` on the credit side.
 */
final class Postings
{
    public function order(Order $order): void
    {
        $amounts = $this->lines($order, true);

        foreach (Payment::query()->where('order_id', $order->id)->get() as $payment) {
            match (true) {
                // Cash on delivery waits in COD clearing for the rider's cash-up; cash taken at a pickup counter is cash.
                $payment->kind === 'cod' && $order->fulfilment === 'delivery' => self::add($amounts, 'cod', $payment->amount_minor),
                $payment->kind === 'cod' => self::add($amounts, 'tender.'.$payment->payment_method_id, $payment->amount_minor),
                in_array($payment->status, ['succeeded', 'refunded'], true) => self::add($amounts, 'tender.'.$payment->payment_method_id, $payment->amount_minor),
                default => null,
            };
        }

        $this->raise($order, EcommercePosting::ORDER, $order->id, $order->delivered_at ?? CarbonImmutable::now(), $amounts);
    }

    public function return(Order $order): void
    {
        $amounts = $this->lines($order, $order->return_fee);
        $refund = $order->total_minor - ($order->return_fee ? 0 : $order->delivery_fee_minor + $order->delivery_tax_minor);
        $order_ = ['mpesa' => 0, 'card' => 1, 'cod' => 2, 'gift_card' => 3, 'points' => 4];
        $payments = Payment::query()->where('order_id', $order->id)->get()->sortBy(fn (Payment $p) => $order_[$p->kind] ?? 9);

        foreach ($payments as $payment) {
            if ($refund <= 0 || ! in_array($payment->status, ['succeeded', 'collected', 'refunded', 'reversed'], true)) {
                continue;
            }

            $take = min($refund, $payment->amount_minor);
            self::add($amounts, 'tender.'.$payment->payment_method_id, $take);
            $refund -= $take;
        }

        $this->raise($order, EcommercePosting::RETURN, $order->id, $order->returned_at ?? CarbonImmutable::now(), $amounts);
    }

    public function cashup(Cashup $cashup): void
    {
        $amounts = [];
        self::add($amounts, 'cash', $cashup->counted_minor);
        self::add($amounts, 'cod', $cashup->expected_minor);
        self::add($amounts, 'shortage', max(0, -$cashup->variance_minor));
        self::add($amounts, 'overage', max(0, $cashup->variance_minor));

        EcommercePosting::dispatch($cashup->tenant_id, $cashup->company_id, EcommercePosting::CASHUP, $cashup->id, $cashup->closed_at->toIso8601String(), $cashup->currency,
            array_filter($amounts, fn (string $minor) => $minor !== '0'), null, [], 'COD-'.substr($cashup->id, -8), ['kind' => 'ecommerce.cashup', 'id' => $cashup->id]);
    }

    /** @return array<string, string> revenue, promotion, tax (and the fee when $fee) of the order's lines */
    private function lines(Order $order, bool $fee): array
    {
        $amounts = [];
        $lines = OrderLine::query()->where('order_id', $order->id)->get();
        $categories = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('category_id', 'id');

        foreach ($lines as $line) {
            $promotion = BigInteger::of($line->discount_minor);

            if ($line->tax_inclusive && $line->total_minor > 0 && ! $promotion->isZero()) {
                $promotion = $promotion->multipliedBy($line->net_minor)->dividedBy($line->total_minor, RoundingMode::HalfUp);
            }

            self::add($amounts, 'revenue.'.($categories[$line->item_id] ?? 'none'), BigInteger::of($line->net_minor)->plus($promotion));
            self::add($amounts, 'promotion', $promotion);
            self::add($amounts, 'tax.'.$line->tax_code_id, $line->tax_minor);
        }

        if ($fee) {
            self::add($amounts, 'delivery', $order->delivery_fee_minor);

            if ($order->delivery_tax_minor > 0) {
                $code = Store::query()->whereKey($order->store_id)->value('delivery_tax_code_id');
                self::add($amounts, 'tax.'.($code ?? 'none'), $order->delivery_tax_minor);
            }
        }

        return $amounts;
    }

    private function raise(Order $order, string $type, string $id, CarbonImmutable $at, array $amounts): void
    {
        $fx = $order->fx;

        EcommercePosting::dispatch($order->tenant_id, $order->company_id, $type, $id, $at->toIso8601String(), $order->currency,
            array_filter($amounts, fn (string $minor) => $minor !== '0'),
            $fx === null || $fx->isIdentity() ? null : $fx->jsonSerialize(),
            array_filter(['branch_id' => $order->branch_id, 'party_id' => $order->party_id]),
            $order->number, ['kind' => 'ecommerce.order', 'id' => $order->id]);
    }

    /** @param array<string, string> $amounts */
    private static function add(array &$amounts, string $field, int|string|BigInteger $minor): void
    {
        $amounts[$field] = (string) BigInteger::of($amounts[$field] ?? '0')->plus($minor);
    }
}
