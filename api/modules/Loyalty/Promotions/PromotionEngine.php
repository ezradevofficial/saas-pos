<?php

namespace Modules\Loyalty\Promotions;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * LOY-10: the promotions engine. Pure and deterministic: the same input
 * gives the same output here and in the till's port
 * (pos/src/loyalty/engine.js); the shared cases in
 * tests/Fixtures/loyalty/promotion-cases.json prove it on both sides.
 *
 * Input:
 *   lines:      [{id, item_id, category_ids, qty, unit_price_minor}] in cart order
 *   context:    {currency, branch_id, customer_tags, coupons, local: {date, weekday, time}}
 *               (`local` is the sale's time in the branch's zone)
 *   promotions: [{id, version, type, priority, percent, amount_minor, currency,
 *                 buy_qty, get_qty, targets, get_targets, components,
 *                 min_spend_minor, branch_ids, customer_tags, schedule, coupon_codes}]
 *
 * Rules (docs/modules/loyalty.md, Decisions): promotions by priority
 * (higher first), then id; a line gets at most one promotion, and lines
 * a promotion applies to are not offered to later ones; every rounding is
 * half up, once; shares go by largest remainder, earlier line first.
 *
 * Output: {lines: [{line_id, promotion_id, version, discount_minor}] in
 * cart order, promotions: [{promotion_id, version, discount_minor,
 * coupon}] in the order applied, total_discount_minor}. Amounts are
 * digit strings.
 */
final class PromotionEngine
{
    public const TYPES = ['percent', 'amount_off', 'buy_x_get_y', 'bundle'];

    /** Percentages carry up to 4 decimals: scaled by 10^4, then divided by 100 × 10^4. */
    private const PERCENT_SCALE = 10000;

    /** Quantities carry up to 6 decimals. */
    private const QTY_SCALE = 1000000;

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $promotions
     * @return array{lines: list<array{line_id: string, promotion_id: string, version: int, discount_minor: string}>, promotions: list<array{promotion_id: string, version: int, discount_minor: string, coupon: ?string}>, total_discount_minor: string}
     */
    public static function evaluate(array $lines, array $context, array $promotions): array
    {
        $prepared = [];

        foreach (array_values($lines) as $index => $line) {
            $unit = BigInteger::of((string) $line['unit_price_minor']);
            $qty = self::scaledQty((string) $line['qty']);
            $prepared[] = [
                'index' => $index,
                'id' => (string) $line['id'],
                'item_id' => (string) $line['item_id'],
                'category_ids' => array_map('strval', (array) ($line['category_ids'] ?? [])),
                'unit' => $unit,
                'units' => $qty->quotient(self::QTY_SCALE),
                'gross' => self::roundHalfUp($unit->multipliedBy($qty), BigInteger::of(self::QTY_SCALE)),
            ];
        }

        $ordered = array_map([self::class, 'normalise'], $promotions);
        usort($ordered, fn (array $a, array $b) => ($b['priority'] <=> $a['priority']) ?: strcmp($a['id'], $b['id']));

        $taken = [];
        $discounts = [];
        $applied = [];

        foreach ($ordered as $promotion) {
            $coupon = self::applicable($promotion, $context);

            if ($coupon === false) {
                continue;
            }

            $free = array_values(array_filter($prepared, fn (array $line) => ! isset($taken[$line['index']])));
            [$shares, $used] = self::apply($promotion, $free);
            $total = array_reduce($shares, fn (BigInteger $sum, BigInteger $share) => $sum->plus($share), BigInteger::zero());

            if ($total->isZero()) {
                continue;
            }

            foreach ($used as $index) {
                $taken[$index] = true;
            }

            foreach ($shares as $index => $share) {
                if ($share->isPositive()) {
                    $discounts[$index] = ['promotion' => $promotion, 'discount' => $share];
                }
            }

            $applied[] = ['promotion_id' => $promotion['id'], 'version' => $promotion['version'], 'discount_minor' => (string) $total, 'coupon' => $coupon];
        }

        ksort($discounts);
        $out = [];
        $sum = BigInteger::zero();

        foreach ($discounts as $index => $entry) {
            $out[] = [
                'line_id' => $prepared[$index]['id'],
                'promotion_id' => $entry['promotion']['id'],
                'version' => $entry['promotion']['version'],
                'discount_minor' => (string) $entry['discount'],
            ];
            $sum = $sum->plus($entry['discount']);
        }

        return ['lines' => $out, 'promotions' => $applied, 'total_discount_minor' => (string) $sum];
    }

    /** LOY-03: points for an eligible amount, rounded down. */
    public static function pointsEarned(string $eligibleMinor, int $earnPoints, string $earnSpendMinor): string
    {
        $eligible = BigInteger::of($eligibleMinor);
        $spend = BigInteger::of($earnSpendMinor);

        if (! $eligible->isPositive() || ! $spend->isPositive() || $earnPoints <= 0) {
            return '0';
        }

        return (string) $eligible->multipliedBy($earnPoints)->quotient($spend);
    }

    /**
     * LOY-04: points to reverse for a refund: the earned share of the
     * refunds so far (rounded down), less what earlier refunds reversed,
     * never more than is left.
     */
    public static function pointsToReverse(string $earned, string $saleTotalMinor, string $refundedMinor, string $alreadyReversed): string
    {
        $earnedPoints = BigInteger::of($earned);
        $total = BigInteger::of($saleTotalMinor);
        $already = BigInteger::of($alreadyReversed);

        if (! $earnedPoints->isPositive() || ! $total->isPositive()) {
            return '0';
        }

        $refunded = BigInteger::min(BigInteger::of($refundedMinor), $total);
        $due = $earnedPoints->multipliedBy($refunded)->quotient($total)->minus($already);
        $left = $earnedPoints->minus($already);

        return (string) BigInteger::max(BigInteger::zero(), BigInteger::min($due, $left));
    }

    /** LOY-05: the value of redeemed points, exact. */
    public static function redeemValue(string $points, string $pointValueMinor): string
    {
        return (string) BigInteger::of($points)->multipliedBy(BigInteger::of($pointValueMinor));
    }

    /** Coupon codes compare trimmed, upper case, without spaces. */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(str_replace(' ', '', trim($code)));
    }

    /** @return array<string, mixed> the promotion with every field the engine reads */
    private static function normalise(array $promotion): array
    {
        $targets = fn (?array $t) => [
            'items' => array_map('strval', (array) ($t['items'] ?? [])),
            'categories' => array_map('strval', (array) ($t['categories'] ?? [])),
        ];
        $schedule = (array) ($promotion['schedule'] ?? []);

        return [
            'id' => (string) $promotion['id'],
            'version' => (int) ($promotion['version'] ?? 1),
            'type' => (string) $promotion['type'],
            'priority' => (int) ($promotion['priority'] ?? 0),
            'percent' => isset($promotion['percent']) ? (string) $promotion['percent'] : null,
            'amount_minor' => isset($promotion['amount_minor']) ? (string) $promotion['amount_minor'] : null,
            'currency' => $promotion['currency'] ?? null,
            'buy_qty' => (int) ($promotion['buy_qty'] ?? 0),
            'get_qty' => (int) ($promotion['get_qty'] ?? 0),
            'targets' => $targets($promotion['targets'] ?? null),
            'get_targets' => isset($promotion['get_targets']) ? $targets($promotion['get_targets']) : null,
            'components' => array_map(fn (array $c) => ['item_id' => (string) $c['item_id'], 'qty' => (int) $c['qty']], (array) ($promotion['components'] ?? [])),
            'min_spend_minor' => isset($promotion['min_spend_minor']) ? (string) $promotion['min_spend_minor'] : null,
            'branch_ids' => array_map('strval', (array) ($promotion['branch_ids'] ?? [])),
            'customer_tags' => array_map('strval', (array) ($promotion['customer_tags'] ?? [])),
            'schedule' => [
                'starts_on' => $schedule['starts_on'] ?? null,
                'ends_on' => $schedule['ends_on'] ?? null,
                'days' => array_map('intval', (array) ($schedule['days'] ?? [])),
                'time_from' => $schedule['time_from'] ?? null,
                'time_to' => $schedule['time_to'] ?? null,
            ],
            'coupon_codes' => isset($promotion['coupon_codes']) ? array_map(fn ($c) => self::normaliseCode((string) $c), (array) $promotion['coupon_codes']) : null,
        ];
    }

    /**
     * Whether the promotion may apply in this context: false when not;
     * else the coupon code it applies with (null without one).
     */
    private static function applicable(array $promotion, array $context): string|false|null
    {
        if (! in_array($promotion['type'], self::TYPES, true)) {
            return false;
        }

        $hasAmount = in_array($promotion['type'], ['amount_off', 'bundle'], true) || $promotion['min_spend_minor'] !== null;

        if ($hasAmount && $promotion['currency'] !== ($context['currency'] ?? null)) {
            return false;
        }

        if ($promotion['branch_ids'] !== [] && ! in_array((string) ($context['branch_id'] ?? ''), $promotion['branch_ids'], true)) {
            return false;
        }

        if ($promotion['customer_tags'] !== [] && array_intersect($promotion['customer_tags'], array_map('strval', (array) ($context['customer_tags'] ?? []))) === []) {
            return false;
        }

        $local = (array) ($context['local'] ?? []);
        $schedule = $promotion['schedule'];
        $date = (string) ($local['date'] ?? '');
        $time = (string) ($local['time'] ?? '');

        if (($schedule['starts_on'] !== null && $date < $schedule['starts_on']) || ($schedule['ends_on'] !== null && $date > $schedule['ends_on'])) {
            return false;
        }

        if ($schedule['days'] !== [] && ! in_array((int) ($local['weekday'] ?? 0), $schedule['days'], true)) {
            return false;
        }

        if ($schedule['time_from'] !== null && $schedule['time_to'] !== null) {
            $from = $schedule['time_from'];
            $to = $schedule['time_to'];
            $inside = $from < $to ? ($time >= $from && $time < $to) : ($time >= $from || $time < $to);

            if (! $inside) {
                return false;
            }
        }

        if ($promotion['coupon_codes'] === null) {
            return null;
        }

        foreach ((array) ($context['coupons'] ?? []) as $code) {
            $code = self::normaliseCode((string) $code);

            if (in_array($code, $promotion['coupon_codes'], true)) {
                return $code;
            }
        }

        return false;
    }

    /**
     * The discount per line index and the lines the promotion uses.
     *
     * @param  list<array<string, mixed>>  $lines  the lines still free, in cart order
     * @return array{0: array<int, BigInteger>, 1: list<int>}
     */
    private static function apply(array $promotion, array $lines): array
    {
        $eligible = array_values(array_filter($lines, fn (array $line) => self::matches($promotion['targets'], $line)));

        return match ($promotion['type']) {
            'percent' => self::percent($promotion, $eligible),
            'amount_off' => self::amountOff($promotion, $eligible),
            'buy_x_get_y' => self::buyGet($promotion, $lines, $eligible),
            'bundle' => self::bundle($promotion, $lines),
        };
    }

    private static function matches(array $targets, array $line): bool
    {
        if ($targets['items'] === [] && $targets['categories'] === []) {
            return true;
        }

        return in_array($line['item_id'], $targets['items'], true) || array_intersect($line['category_ids'], $targets['categories']) !== [];
    }

    private static function minSpendMet(array $promotion, array $eligible): bool
    {
        if ($promotion['min_spend_minor'] === null) {
            return true;
        }

        return self::sum(array_column($eligible, 'gross'))->isGreaterThanOrEqualTo(BigInteger::of($promotion['min_spend_minor']));
    }

    /** @return array{0: array<int, BigInteger>, 1: list<int>} */
    private static function percent(array $promotion, array $eligible): array
    {
        if ($eligible === [] || ! self::minSpendMet($promotion, $eligible)) {
            return [[], []];
        }

        $percent = self::scaledPercent((string) $promotion['percent']);
        $shares = [];

        foreach ($eligible as $line) {
            $shares[$line['index']] = self::percentOf($line['gross'], $percent);
        }

        return [$shares, array_column($eligible, 'index')];
    }

    /** @return array{0: array<int, BigInteger>, 1: list<int>} */
    private static function amountOff(array $promotion, array $eligible): array
    {
        if ($eligible === [] || ! self::minSpendMet($promotion, $eligible)) {
            return [[], []];
        }

        $subtotal = self::sum(array_column($eligible, 'gross'));
        $amount = BigInteger::min(BigInteger::of((string) $promotion['amount_minor']), $subtotal);
        $parts = [];

        foreach ($eligible as $line) {
            $parts[$line['index']] = $line['gross'];
        }

        return [self::allocate($amount, $parts), array_column($eligible, 'index')];
    }

    /** @return array{0: array<int, BigInteger>, 1: list<int>} */
    private static function buyGet(array $promotion, array $lines, array $buySet): array
    {
        $x = $promotion['buy_qty'];
        $y = $promotion['get_qty'];

        if ($x < 1 || $y < 1 || $buySet === []) {
            return [[], []];
        }

        if ($promotion['get_targets'] === null) {
            $units = self::units($buySet);
            $count = intdiv(count($units), $x + $y) * $y;
            $getLines = $buySet;
            $used = array_column($buySet, 'index');
        } else {
            $buyIndexes = array_column($buySet, 'index');
            $getLines = array_values(array_filter($lines, fn (array $line) => ! in_array($line['index'], $buyIndexes, true) && self::matches($promotion['get_targets'], $line)));
            $units = self::units($getLines);
            $buyUnits = array_reduce($buySet, fn (int $sum, array $line) => $sum + self::intUnits($line), 0);
            $count = min(intdiv($buyUnits, $x) * $y, count($units));
            $used = [...$buyIndexes, ...array_column($getLines, 'index')];
        }

        if ($count === 0) {
            return [[], []];
        }

        // The cheapest units; on an equal price, the later line's first.
        usort($units, fn (array $a, array $b) => $a['price']->compareTo($b['price']) ?: $b['index'] <=> $a['index']);
        $percent = self::scaledPercent((string) $promotion['percent']);
        $shares = [];

        foreach (array_slice($units, 0, $count) as $unit) {
            $shares[$unit['index']] = ($shares[$unit['index']] ?? BigInteger::zero())->plus(self::percentOf($unit['price'], $percent));
        }

        sort($used);

        return [$shares, $used];
    }

    /** @return array{0: array<int, BigInteger>, 1: list<int>} */
    private static function bundle(array $promotion, array $lines): array
    {
        $components = $promotion['components'];

        if ($components === []) {
            return [[], []];
        }

        $bundles = null;

        foreach ($components as $component) {
            if ($component['qty'] < 1) {
                return [[], []];
            }

            $available = array_reduce($lines, fn (int $sum, array $line) => $sum + ($line['item_id'] === $component['item_id'] ? self::intUnits($line) : 0), 0);
            $possible = intdiv($available, $component['qty']);
            $bundles = $bundles === null ? $possible : min($bundles, $possible);
        }

        if ($bundles < 1) {
            return [[], []];
        }

        $contributions = [];

        foreach ($components as $component) {
            $needed = $bundles * $component['qty'];

            foreach ($lines as $line) {
                if ($needed === 0 || $line['item_id'] !== $component['item_id']) {
                    continue;
                }

                $take = min($needed, self::intUnits($line));

                if ($take > 0) {
                    $contributions[$line['index']] = ($contributions[$line['index']] ?? BigInteger::zero())->plus($line['unit']->multipliedBy($take));
                    $needed -= $take;
                }
            }
        }

        ksort($contributions);
        $gross = self::sum(array_values($contributions));
        $discount = $gross->minus(BigInteger::of((string) $promotion['amount_minor'])->multipliedBy($bundles));

        if (! $discount->isPositive()) {
            return [[], []];
        }

        return [self::allocate($discount, $contributions), array_keys($contributions)];
    }

    /**
     * Share $amount over $parts (index => weight) by largest remainder:
     * floors first, then one unit each to the largest remainders, the
     * earlier index first on a tie.
     *
     * @param  array<int, BigInteger>  $parts
     * @return array<int, BigInteger>
     */
    private static function allocate(BigInteger $amount, array $parts): array
    {
        $total = self::sum(array_values($parts));

        if ($total->isZero()) {
            return [];
        }

        $shares = [];
        $remainders = [];
        $given = BigInteger::zero();

        foreach ($parts as $index => $weight) {
            $product = $amount->multipliedBy($weight);
            $shares[$index] = $product->quotient($total);
            $remainders[] = ['index' => $index, 'remainder' => $product->remainder($total)];
            $given = $given->plus($shares[$index]);
        }

        usort($remainders, fn (array $a, array $b) => $b['remainder']->compareTo($a['remainder']) ?: $a['index'] <=> $b['index']);
        $left = $amount->minus($given)->toInt();

        for ($i = 0; $i < $left; $i++) {
            $index = $remainders[$i]['index'];
            $shares[$index] = $shares[$index]->plus(1);
        }

        return $shares;
    }

    /** @return list<array{index: int, price: BigInteger}> one entry per whole unit */
    private static function units(array $lines): array
    {
        $units = [];

        foreach ($lines as $line) {
            for ($i = 0, $n = self::intUnits($line); $i < $n; $i++) {
                $units[] = ['index' => $line['index'], 'price' => $line['unit']];
            }
        }

        return $units;
    }

    private static function intUnits(array $line): int
    {
        return $line['units']->toInt();
    }

    private static function percentOf(BigInteger $amount, BigInteger $scaledPercent): BigInteger
    {
        return self::roundHalfUp($amount->multipliedBy($scaledPercent), BigInteger::of(100 * self::PERCENT_SCALE));
    }

    private static function roundHalfUp(BigInteger $numerator, BigInteger $denominator): BigInteger
    {
        return $numerator->dividedBy($denominator, RoundingMode::HalfUp);
    }

    private static function scaledQty(string $qty): BigInteger
    {
        return self::scaled($qty, 6);
    }

    private static function scaledPercent(string $percent): BigInteger
    {
        return self::scaled($percent, 4);
    }

    /** A non-negative decimal string as an integer scaled by 10^$places (extra places are cut). */
    private static function scaled(string $value, int $places): BigInteger
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = substr(str_pad($fraction, $places, '0'), 0, $places);

        return BigInteger::of(($whole === '' ? '0' : $whole).$fraction);
    }

    /** @param list<BigInteger> $values */
    private static function sum(array $values): BigInteger
    {
        return array_reduce($values, fn (BigInteger $sum, BigInteger $value) => $sum->plus($value), BigInteger::zero());
    }
}
