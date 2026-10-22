<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Ecommerce\Events\ExchangeIssued;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Events\OrderLinesReturned;
use Modules\Ecommerce\Integrations\Postings;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent as History;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\ReturnLine;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-42, ECOM-43: partial returns and exchanges of a delivered order.
 *
 * What comes back is a share of order lines (any quantity not returned
 * yet, whole numbers for `count` units, else up to 3 decimals). Its money
 * is the line's own, never priced again: for a line of quantity Q with
 * amount X (gross, discount, tax, total), r units returned in all are worth
 * round_half_up(X × r / Q), and a return takes what that share grew by, so
 * returning every unit, in any number of returns, gives back the line
 * exactly. The delivery fee is never part of a partial return
 * (decision 15).
 *
 * An exchange adds items given instead (`out`), priced by the server as
 * the storefront prices them today (Pricing: the store's price list, tax,
 * Loyalty's promotions for everyone; no coupon, points or gift card), out
 * of the order's location under the stock locks. The difference is
 * refunded (refund due) or collected (collect due).
 *
 * Creating one (`ecommerce.order.manage`) keeps the refund within the
 * user's `max_refund_amount` (RBAC-06), is recorded in the order's history
 * and audited (AUD-01), and raises after commit OrderLinesReturned
 * (Inventory brings the stock back, the fiscal credit note) and the
 * `ecommerce.return` posting. An exchange's item leaves, posts as a sale
 * and is fiscalised once nothing is left to collect (ExchangeIssued).
 * A whole-order return stays for orders without partial returns.
 */
final class Returns
{
    /** Quote problems that are about delivery, not about the items (an exchange is handed over at the counter). */
    private const IGNORED_PROBLEMS = ['pickup_unavailable', 'delivery_unavailable', 'zone_required', 'zone_unknown', 'below_minimum', 'cart_empty', 'coupon_invalid'];

    public function __construct(
        private readonly Pricing $pricing,
        private readonly Reservations $reservations,
        private readonly OrderFlow $flow,
        private readonly Postings $postings,
    ) {}

    /**
     * What a return would be, without storing anything.
     *
     * @param  array{reason?: string, lines: list<array{line_id: string, qty: string}>, exchange?: list<array{product_id: string, qty: string}>}  $input
     * @return array<string, mixed>
     */
    public function quote(Order $order, array $input): array
    {
        $this->assertReturnable($order);

        return $this->compute($order, $input, CarbonImmutable::now());
    }

    /** @param array{reason: string, lines: list<array{line_id: string, qty: string}>, exchange?: list<array{product_id: string, qty: string}>} $input */
    public function create(Order $order, User $by, array $input): OrderReturn
    {
        $return = Tx::run(function () use ($order, $by, $input) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertReturnable($order);

            $exchange = array_values($input['exchange'] ?? []);

            if ($exchange !== []) {
                // ECOM-14: the exchange item is checked under the same locks as a placement.
                $ids = array_values(array_filter(array_map(fn ($e) => (string) ($e['product_id'] ?? ''), $exchange), Str::isUuid(...)));
                $items = Product::query()->where('store_id', $order->store_id)->whereKey($ids)->pluck('item_id')->all();
                $this->reservations->lock($order->location_id, $items);
            }

            $at = CarbonImmutable::now();
            $quote = $this->compute($order, $input, $at);

            if ($quote['refund_minor'] > 0) {
                $this->flow->assertRefundLimit($order, $by, $quote['refund_minor']);
            }

            $seq = (int) OrderReturn::query()->where('order_id', $order->id)->max('seq') + 1;
            $return = OrderReturn::create([
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'seq' => $seq,
                'number' => mb_substr($order->number.'-R'.$seq, 0, 90),
                'kind' => $quote['out'] === [] ? OrderReturn::RETURN : OrderReturn::EXCHANGE,
                'reason' => mb_substr(trim((string) $input['reason']), 0, 500),
                'currency' => $order->currency,
                'returned_net_minor' => $quote['returned_net_minor'],
                'returned_tax_minor' => $quote['returned_tax_minor'],
                'returned_total_minor' => $quote['returned_total_minor'],
                'exchange_net_minor' => $quote['exchange_net_minor'],
                'exchange_tax_minor' => $quote['exchange_tax_minor'],
                'exchange_total_minor' => $quote['exchange_total_minor'],
                'refund_minor' => $quote['refund_minor'],
                'collect_minor' => $quote['collect_minor'],
                'base_currency' => $order->base_currency,
                'fx' => $order->fx,
                'base_returned_total_minor' => self::base($order, $quote['returned_total_minor']),
                'base_exchange_total_minor' => self::base($order, $quote['exchange_total_minor']),
                'refund_status' => $quote['refund_minor'] > 0 ? 'due' : 'none',
                'collect_status' => $quote['collect_minor'] > 0 ? 'due' : 'none',
                'exchange_sale_id' => $quote['out'] === [] ? null : (string) Str::uuid7(),
                'created_by' => $by->id,
                'at' => $at,
            ]);

            $no = 0;
            foreach ([...$quote['in'], ...$quote['out']] as $line) {
                ReturnLine::create([
                    'return_id' => $return->id, 'order_id' => $order->id, 'line_no' => ++$no,
                    ...array_intersect_key($line, array_flip(['direction', 'order_line_id', 'product_id', 'item_id', 'uom_id', 'name', 'qty', 'qty_base', 'unit_price_minor',
                        'gross_minor', 'discount_minor', 'net_minor', 'tax_minor', 'total_minor', 'tax_code_id', 'tax_rate', 'tax_inclusive', 'promotion'])),
                ]);
            }

            History::create(['order_id' => $order->id, 'from' => $order->status, 'to' => $order->status, 'payment_status' => $order->payment_status,
                'note' => mb_substr(__('ecommerce.returns.history', ['number' => $return->number, 'reason' => $return->reason]), 0, 500), 'user_id' => $by->id, 'at' => $at]);

            $in = ReturnLine::query()->where('return_id', $return->id)->where('direction', ReturnLine::IN)->orderBy('line_no')->get();
            OrderLinesReturned::dispatch($order->tenant_id, $return->id, $order->id, $return->number, $order->company_id, $order->branch_id, $order->location_id,
                $at->toIso8601String(), $by->id, $in->map(fn (ReturnLine $l) => [
                    'line_id' => $l->id, 'order_line_id' => (string) $l->order_line_id, 'item_id' => $l->item_id, 'uom_id' => $l->uom_id, 'qty' => (string) $l->qty, 'qty_base' => (string) $l->qty_base,
                ])->values()->all());
            OrderEvent::dispatch($order->tenant_id, OrderEvent::STATUS_CHANGED, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor, $order->status, $order->status);
            $this->postings->partialReturn($order, $return);

            if ($return->exchange_sale_id !== null && $return->collect_status === 'none') {
                $this->issue($order, $return, $by);
            }

            return $return;
        });

        return $return->refresh();
    }

    /**
     * ECOM-43: an exchange's difference paid by the customer, by any active
     * payment method of the order's company in its currency (cash at the
     * counter, a till M-Pesa with its reference).
     */
    public function collect(OrderReturn $return, User $by, string $paymentMethodId, ?string $reference): OrderReturn
    {
        return Tx::run(function () use ($return, $by, $paymentMethodId, $reference) {
            $order = Order::query()->whereKey($return->order_id)->lockForUpdate()->firstOrFail();
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->collect_status !== 'due') {
                throw EcomError::make('collect_not_due', status: 409);
            }

            $method = PaymentMethod::query()->whereKey($paymentMethodId)->where('company_id', $order->company_id)->where('active', true)->first();

            if ($method === null || ($method->currency !== null && $method->currency !== $order->currency)) {
                throw EcomError::make('collect_method_invalid', field: 'payment_method_id');
            }

            $return->forceFill([
                'collect_status' => 'collected', 'collect_payment_method_id' => $method->id, 'collected_at' => CarbonImmutable::now(), 'collected_by' => $by->id,
                'collect_reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 120),
            ])->save();
            $this->issue($order, $return, $by);

            return $return->refresh();
        });
    }

    /** The exchange's item leaves, posts as a sale and is fiscalised (once). */
    private function issue(Order $order, OrderReturn $return, User $by): void
    {
        if ($return->exchange_posted_at !== null || $return->exchange_sale_id === null) {
            return;
        }

        $at = CarbonImmutable::now();
        $return->forceFill(['exchange_posted_at' => $at])->save();
        $out = ReturnLine::query()->where('return_id', $return->id)->where('direction', ReturnLine::OUT)->orderBy('line_no')->get();
        ExchangeIssued::dispatch($order->tenant_id, (string) $return->exchange_sale_id, $return->id, $order->id, $return->number, $order->company_id, $order->branch_id,
            $order->location_id, $at->toIso8601String(), $by->id, $out->map(fn (ReturnLine $l) => [
                'line_id' => $l->id, 'item_id' => $l->item_id, 'uom_id' => $l->uom_id, 'qty' => (string) $l->qty, 'qty_base' => (string) $l->qty_base,
            ])->values()->all());
        $this->postings->exchange($order, $return);
    }

    private function assertReturnable(Order $order): void
    {
        if ($order->status !== Order::DELIVERED) {
            throw EcomError::make('return_not_allowed', ['status' => __("ecommerce.statuses.{$order->status}")], status: 409);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function compute(Order $order, array $input, CarbonImmutable $at): array
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->keyBy('id');
        $returned = self::returned($order);
        $uoms = Uom::query()->whereKey($lines->pluck('uom_id')->unique()->all())->pluck('kind', 'id');
        $in = [];
        $asked = [];

        foreach (array_values($input['lines'] ?? []) as $index => $entry) {
            $line = $lines->get((string) ($entry['line_id'] ?? ''));

            if ($line === null || isset($asked[$line->id])) {
                throw EcomError::make('return_line_invalid', field: "lines.{$index}.line_id");
            }

            $asked[$line->id] = true;
            $qty = self::qty((string) ($entry['qty'] ?? ''), ($uoms[$line->uom_id] ?? null) === 'count');
            $before = BigDecimal::of($returned[$line->id] ?? '0');
            $total = BigDecimal::of((string) $line->qty);

            if ($qty === null || $before->plus($qty)->isGreaterThan($total)) {
                throw EcomError::make('return_qty_invalid', ['product' => $line->name, 'qty' => self::trim($total->minus($before))], "lines.{$index}.qty");
            }

            $after = $before->plus($qty);
            $share = fn (int $amount) => self::share($amount, $after, $total) - self::share($amount, $before, $total);
            $in[] = [
                'direction' => ReturnLine::IN,
                'order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'name' => $line->name,
                'qty' => (string) $qty,
                'qty_base' => (string) ($after->isEqualTo($total)
                    ? BigDecimal::of((string) $line->qty_base)->minus(BigDecimal::of((string) $line->qty_base)->multipliedBy($before)->dividedBy($total, 6, RoundingMode::HalfUp))
                    : BigDecimal::of((string) $line->qty_base)->multipliedBy($qty)->dividedBy($total, 6, RoundingMode::HalfUp)),
                'unit_price_minor' => $line->unit_price_minor,
                'gross_minor' => $share($line->gross_minor),
                'discount_minor' => $share($line->discount_minor),
                'net_minor' => $share($line->net_minor),
                'tax_minor' => $share($line->tax_minor),
                'total_minor' => $share($line->total_minor),
                'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                'tax_inclusive' => $line->tax_inclusive,
                'promotion' => $line->promotion,
            ];
        }

        if ($in === []) {
            throw EcomError::make('return_lines_required', field: 'lines');
        }

        $out = [];
        $exchange = array_values($input['exchange'] ?? []);

        if ($exchange !== []) {
            $store = Store::query()->findOrFail($order->store_id);
            $quote = $this->pricing->quote($store, ['lines' => $exchange, 'fulfilment' => 'pickup', 'pickup_location_id' => $order->location_id], null, $at);
            $problem = collect($quote['problems'])->first(fn (array $p) => ! in_array($p['code'], self::IGNORED_PROBLEMS, true));

            if ($problem !== null) {
                $field = isset($problem['line']) ? "exchange.{$problem['line']}" : 'exchange';

                throw new ApiException(422, $problem['code'], $problem['message'], [$field => [$problem['message']]]);
            }

            foreach ($quote['lines'] as $line) {
                $out[] = [
                    'direction' => ReturnLine::OUT, 'order_line_id' => null,
                    ...array_intersect_key($line, array_flip(['product_id', 'item_id', 'uom_id', 'name', 'qty', 'qty_base', 'unit_price_minor', 'gross_minor', 'discount_minor', 'net_minor',
                        'tax_minor', 'total_minor', 'tax_code_id', 'tax_rate', 'tax_inclusive', 'promotion'])),
                ];
            }
        }

        $sum = fn (array $rows, string $key) => (int) array_sum(array_column($rows, $key));
        $returnedTotal = $sum($in, 'total_minor');
        $exchangeTotal = $sum($out, 'total_minor');

        return [
            'currency' => $order->currency,
            'in' => $in,
            'out' => $out,
            'returned_net_minor' => $sum($in, 'net_minor'),
            'returned_tax_minor' => $sum($in, 'tax_minor'),
            'returned_total_minor' => $returnedTotal,
            'exchange_net_minor' => $sum($out, 'net_minor'),
            'exchange_tax_minor' => $sum($out, 'tax_minor'),
            'exchange_total_minor' => $exchangeTotal,
            'refund_minor' => max(0, $returnedTotal - $exchangeTotal),
            'collect_minor' => max(0, $exchangeTotal - $returnedTotal),
        ];
    }

    /** @return array<string, string> order line id => quantity returned so far */
    public static function returned(Order $order): array
    {
        return ReturnLine::query()->where('order_id', $order->id)->where('direction', ReturnLine::IN)
            ->selectRaw('order_line_id, sum(qty) as qty')->groupBy('order_line_id')->pluck('qty', 'order_line_id')
            ->map(fn ($qty) => (string) $qty)->all();
    }

    public static function hasReturns(Order $order): bool
    {
        return OrderReturn::query()->where('order_id', $order->id)->exists();
    }

    /** round_half_up($amount × $part / $whole), in minor units. */
    private static function share(int $amount, BigDecimal $part, BigDecimal $whole): int
    {
        return (int) (string) BigDecimal::of($amount)->multipliedBy($part)->dividedBy($whole, 0, RoundingMode::HalfUp);
    }

    /** The order's base amount for $minor of its currency, at the order's own rate (CUR-04). */
    public static function base(Order $order, int $minor): int
    {
        return $order->total_minor === 0 ? 0 : (int) (string) BigDecimal::of($order->base_total_minor)->multipliedBy($minor)->dividedBy($order->total_minor, 0, RoundingMode::HalfUp);
    }

    private static function qty(string $value, bool $whole): ?BigDecimal
    {
        $pattern = $whole ? '/^\d{1,6}$/' : '/^\d{1,6}(\.\d{1,3})?$/';

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        $qty = BigDecimal::of($value);

        return $qty->isPositive() ? $qty : null;
    }

    private static function trim(BigDecimal $value): string
    {
        return rtrim(rtrim((string) $value->toScale(3, RoundingMode::Down), '0'), '.') ?: '0';
    }

    /**
     * How a return's money goes back to the order's tenders, in order
     * (M-Pesa, card, cash, gift card, points), after what the earlier
     * returns took: the same split for the posting and the refund.
     *
     * @return list<array{payment: Payment, amount_minor: int}>
     */
    public static function allocation(Order $order, OrderReturn $return): array
    {
        $rank = ['mpesa' => 0, 'card' => 1, 'cod' => 2, 'gift_card' => 3, 'points' => 4];
        $payments = Payment::query()->where('order_id', $order->id)->whereIn('status', ['succeeded', 'collected', 'refunded', 'reversed'])->get()
            ->sortBy(fn (Payment $p) => [$rank[$p->kind] ?? 9, $p->created_at?->getTimestamp() ?? 0, $p->id])->values();
        $left = $payments->mapWithKeys(fn (Payment $p) => [$p->id => $p->amount_minor])->all();
        $earlier = OrderReturn::query()->where('order_id', $order->id)->where('seq', '<', $return->seq)->orderBy('seq')->pluck('returned_total_minor');

        foreach ([...$earlier->all(), $return->returned_total_minor] as $index => $amount) {
            $parts = [];
            $amount = (int) $amount;

            foreach ($payments as $payment) {
                $take = min($amount, $left[$payment->id]);

                if ($take > 0) {
                    $parts[] = ['payment' => $payment, 'amount_minor' => $take];
                    $left[$payment->id] -= $take;
                    $amount -= $take;
                }
            }

            if ($index === $earlier->count()) {
                return $parts;
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> the order's returns, for the back office */
    public static function present(Order $order): array
    {
        return OrderReturn::query()->where('order_id', $order->id)->orderBy('seq')->with('lines')->get()
            ->map(fn (OrderReturn $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'kind' => $r->kind,
                'reason' => $r->reason,
                'currency' => $r->currency,
                'lines' => self::lines($r->lines),
                'returned_total_minor' => (string) $r->returned_total_minor,
                'exchange_total_minor' => (string) $r->exchange_total_minor,
                'refund_minor' => (string) $r->refund_minor,
                'collect_minor' => (string) $r->collect_minor,
                'refund_status' => $r->refund_status,
                'collect_status' => $r->collect_status,
                'refund_reference' => $r->refund_reference,
                'collect_reference' => $r->collect_reference,
                'created_by' => ['id' => $r->created_by, 'name' => (string) User::query()->whereKey($r->created_by)->value('name')],
                'created_at' => $r->at->toIso8601String(),
                'refunded_at' => $r->refunded_at?->toIso8601String(),
                'collected_at' => $r->collected_at?->toIso8601String(),
                'flags' => $r->flags ?? [],
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> what the customer sees of their order's returns */
    public static function forCustomer(Order $order): array
    {
        return OrderReturn::query()->where('order_id', $order->id)->orderBy('seq')->with('lines')->get()
            ->map(fn (OrderReturn $r) => [
                'number' => $r->number,
                'kind' => $r->kind,
                'lines' => $r->lines->map(fn (ReturnLine $l) => ['name' => $l->name, 'qty' => self::trim(BigDecimal::of((string) $l->qty)), 'direction' => $l->direction])->values()->all(),
                'refund_minor' => (string) $r->refund_minor,
                'collect_minor' => (string) $r->collect_minor,
                'refund_status' => $r->refund_status,
            ])->values()->all();
    }

    /** @param iterable<ReturnLine>|array<int, array<string, mixed>> $lines */
    public static function lines(iterable $lines): array
    {
        return collect($lines)->map(function ($l) {
            $l = $l instanceof ReturnLine ? [...$l->getAttributes(), 'id' => $l->id] : $l;

            return [
                'id' => $l['id'] ?? null, 'direction' => $l['direction'], 'order_line_id' => $l['order_line_id'] ?? null, 'product_id' => $l['product_id'] ?? null,
                'name' => $l['name'], 'qty' => self::trim(BigDecimal::of((string) $l['qty'])), 'unit_price_minor' => (string) $l['unit_price_minor'],
                'tax_minor' => (string) $l['tax_minor'], 'total_minor' => (string) $l['total_minor'],
            ];
        })->values()->all();
    }

    /** @param array<string, mixed> $quote compute() */
    public static function presentQuote(array $quote): array
    {
        $lines = self::lines([...$quote['in'], ...$quote['out']]);

        return [
            'currency' => $quote['currency'],
            'lines' => array_map(fn (array $l) => array_diff_key($l, ['id' => true]), $lines),
            ...array_map(fn (int $v) => (string) $v, array_intersect_key($quote, array_flip(['returned_total_minor', 'exchange_total_minor', 'refund_minor', 'collect_minor']))),
        ];
    }

    /** @return Collection<string, string> order line id => returned qty, for Present */
    public static function returnedQty(Order $order): Collection
    {
        return collect(self::returned($order))->map(fn (string $q) => self::trim(BigDecimal::of($q)));
    }
}
