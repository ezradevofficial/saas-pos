<?php

namespace Modules\Restaurant\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * REST-13, REST-14: the maths of an order's bill, pure and exact. The till
 * has the same rules in `pos/src/restaurant/bill.js`; both replay
 * `tests/Fixtures/restaurant/bill.json`, so they agree to the minor unit.
 *
 * A line is `{qty, unit_price_minor, modifiers: [{price_minor}], tax_rate?,
 * tax_inclusive, void?, seat?, id}`. Amounts are minor units (digit
 * strings out, ints or strings in), rounding is half up.
 *
 * - gross = round(qty x (unit price + modifier effects));
 * - net = gross, or gross / (1 + rate) when the price includes tax;
 * - payable = gross when tax-inclusive, else gross + tax on the net;
 * - service charge = round(sum of live nets x percent), rung as a line of
 *   the service item taxed at that item's rate, exclusive of tax;
 * - tip = a tax-free amount.
 * Void lines count for nothing.
 */
final class Bill
{
    private const SCALE = 1_000_000;

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{percent?: string|int|float|null, tax_rate?: string|null}  $service
     * @return array{lines: list<array<string, string>>, net_minor: string, tax_minor: string, lines_minor: string, service_charge_minor: string, service_tax_minor: string, tip_minor: string, total_minor: string}
     */
    public static function totals(array $lines, array $service = [], string|int $tip = 0): array
    {
        $rows = [];
        $net = BigInteger::zero();
        $payable = BigInteger::zero();
        $tax = BigInteger::zero();

        foreach ($lines as $line) {
            if (! empty($line['void'])) {
                continue;
            }

            $amounts = self::line($line);
            $rows[] = ['id' => (string) ($line['id'] ?? ''), 'gross_minor' => (string) $amounts['gross'], 'net_minor' => (string) $amounts['net'], 'tax_minor' => (string) $amounts['tax'], 'payable_minor' => (string) $amounts['payable']];
            $net = $net->plus($amounts['net']);
            $payable = $payable->plus($amounts['payable']);
            $tax = $tax->plus($amounts['tax']);
        }

        [$charge, $chargeTax] = self::serviceCharge($net, $service);
        $tipAmount = BigInteger::of($tip);

        if ($tipAmount->isNegative()) {
            throw new InvalidArgumentException('A tip cannot be negative.');
        }

        return [
            'lines' => $rows,
            'net_minor' => (string) $net,
            'tax_minor' => (string) $tax,
            'lines_minor' => (string) $payable,
            'service_charge_minor' => (string) $charge,
            'service_tax_minor' => (string) $chargeTax,
            'tip_minor' => (string) $tipAmount,
            'total_minor' => (string) $payable->plus($charge)->plus($chargeTax)->plus($tipAmount),
        ];
    }

    /**
     * REST-14: the parts of a split. `mode` is `full`, `item` (`groups`:
     * lists of line ids; every live line in exactly one group), `seat` (one
     * part per distinct seat in order; lines without a seat are shared
     * equally) or `equal` (`parts` of them). The parts add up exactly to
     * the order's total, by largest remainder.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{percent?: string|int|float|null, tax_rate?: string|null}  $service
     * @param  array{parts?: int, groups?: list<list<string>>}  $options
     * @return list<array<string, mixed>>
     */
    public static function split(array $lines, string $mode, array $service = [], string|int $tip = 0, array $options = []): array
    {
        $whole = self::totals($lines, $service, $tip);
        $live = array_values(array_filter($lines, fn (array $line) => empty($line['void'])));
        $amounts = [];
        $shared = [];
        $ids = null;

        foreach ($live as $line) {
            $amounts[(string) $line['id']] = self::line($line);
        }

        switch ($mode) {
            case 'full':
                return [self::part($whole, null, null)];
            case 'equal':
                $count = (int) ($options['parts'] ?? 0);

                if ($count < 2 || $count > 20) {
                    throw new InvalidArgumentException('Equal split needs 2 to 20 parts.');
                }

                $weights = array_fill(0, $count, BigInteger::one());
                $net = $weights;

                break;
            case 'item':
                $groups = $options['groups'] ?? [];
                $seen = [];

                foreach ($groups as $group) {
                    foreach ($group as $id) {
                        if (! isset($amounts[$id]) || isset($seen[$id])) {
                            throw new InvalidArgumentException("Line [{$id}] is not a live line or is in two groups.");
                        }
                        $seen[$id] = true;
                    }
                }

                if (count($groups) < 2 || count($seen) !== count($amounts)) {
                    throw new InvalidArgumentException('Split by item needs 2 or more groups covering every live line.');
                }

                [$weights, $net] = self::byLines($groups, $amounts, []);
                $ids = $groups;

                break;
            case 'seat':
                $seats = [];

                foreach ($live as $line) {
                    if (($line['seat'] ?? null) !== null) {
                        $seats[(int) $line['seat']] = true;
                    }
                }

                ksort($seats);
                $seatNumbers = array_keys($seats);

                if (count($seatNumbers) < 2) {
                    throw new InvalidArgumentException('Split by seat needs 2 or more seats with lines.');
                }

                $groups = [];

                foreach ($seatNumbers as $seat) {
                    $groups[] = array_values(array_map(fn ($l) => (string) $l['id'], array_filter($live, fn ($l) => ($l['seat'] ?? null) !== null && (int) $l['seat'] === $seat)));
                }

                foreach ($live as $line) {
                    if (($line['seat'] ?? null) === null) {
                        $shared[] = (string) $line['id'];
                    }
                }

                [$weights, $net] = self::byLines($groups, $amounts, $shared);
                $ids = $groups;

                break;
            default:
                throw new InvalidArgumentException("Unknown split mode [{$mode}].");
        }

        $count = count($weights);
        $lineShares = $mode === 'equal'
            ? self::allocate(BigInteger::of($whole['lines_minor']), $weights)
            : $weights;
        $chargeShares = self::allocate(BigInteger::of($whole['service_charge_minor']), $mode === 'equal' ? $weights : $net);
        $chargeTaxShares = self::allocate(BigInteger::of($whole['service_tax_minor']), $mode === 'equal' ? $weights : $net);
        $tipShares = self::allocate(BigInteger::of($whole['tip_minor']), $lineShares);
        $parts = [];

        foreach ($lineShares as $index => $lines) {
            $parts[] = self::part([
                'lines_minor' => (string) $lines,
                'service_charge_minor' => (string) $chargeShares[$index],
                'service_tax_minor' => (string) $chargeTaxShares[$index],
                'tip_minor' => (string) $tipShares[$index],
                'total_minor' => (string) $lines->plus($chargeShares[$index])->plus($chargeTaxShares[$index])->plus($tipShares[$index]),
            ], $ids[$index] ?? null, $mode === 'equal' ? [$index, $count] : null);
        }

        return $parts;
    }

    /**
     * A tip as a percentage of the live lines' payable amount (before the
     * service charge), rounded half up; a convenience of the split preview.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function tipFromPercent(array $lines, string $percent): string
    {
        $payable = self::totals($lines)['lines_minor'];

        return (string) self::roundDiv(BigInteger::of($payable)->multipliedBy(self::rate($percent)), BigInteger::of(self::SCALE));
    }

    /**
     * REST-14: the quantity of $qty that part $index of $count takes in an
     * equal split: 1/count rounded down to 6 places, the last part taking
     * what is left, so the parts add up to the quantity exactly.
     */
    public static function equalQty(string $qty, int $index, int $count): string
    {
        $total = BigDecimal::of($qty)->toScale(6, RoundingMode::HalfUp);
        $each = $total->dividedBy($count, 6, RoundingMode::Down);

        if ($index < $count - 1) {
            return (string) $each->toScale(6);
        }

        return (string) $total->minus($each->multipliedBy($count - 1))->toScale(6);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{gross: BigInteger, net: BigInteger, tax: BigInteger, payable: BigInteger}
     */
    public static function line(array $line): array
    {
        $effects = array_sum(array_map(fn ($m) => (int) $m['price_minor'], $line['modifiers'] ?? []));
        $qty = BigDecimal::of((string) $line['qty'])->toScale(6, RoundingMode::HalfUp)->getUnscaledValue();
        $gross = self::roundDiv($qty->multipliedBy(BigInteger::of((string) $line['unit_price_minor'])->plus($effects)), BigInteger::of(self::SCALE));
        $rate = isset($line['tax_rate']) && $line['tax_rate'] !== null ? self::rate((string) $line['tax_rate']) : BigInteger::zero();
        $inclusive = ! empty($line['tax_inclusive']);

        if ($inclusive) {
            $net = self::roundDiv($gross->multipliedBy(self::SCALE), BigInteger::of(self::SCALE)->plus($rate));

            return ['gross' => $gross, 'net' => $net, 'tax' => $gross->minus($net), 'payable' => $gross];
        }

        $tax = self::roundDiv($gross->multipliedBy($rate), BigInteger::of(self::SCALE));

        return ['gross' => $gross, 'net' => $gross, 'tax' => $tax, 'payable' => $gross->plus($tax)];
    }

    /** @return array{0: BigInteger, 1: BigInteger} the charge and the tax on it */
    private static function serviceCharge(BigInteger $net, array $service): array
    {
        $percent = $service['percent'] ?? null;

        if ($percent === null || (float) $percent <= 0) {
            return [BigInteger::zero(), BigInteger::zero()];
        }

        $charge = self::roundDiv($net->multipliedBy(self::rate((string) $percent)), BigInteger::of(self::SCALE));
        $tax = isset($service['tax_rate']) && $service['tax_rate'] !== null
            ? self::roundDiv($charge->multipliedBy(self::rate((string) $service['tax_rate'])), BigInteger::of(self::SCALE))
            : BigInteger::zero();

        return [$charge, $tax];
    }

    /** A percentage as parts per million of a unit: 16 % is 160 000 (four decimals kept). */
    private static function rate(string $percent): BigInteger
    {
        return BigDecimal::of($percent)->toScale(4, RoundingMode::HalfUp)->getUnscaledValue();
    }

    /** a / b rounded half up, both positive or a zero. */
    private static function roundDiv(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->multipliedBy(2)->plus($b)->quotient($b->multipliedBy(2));
    }

    /**
     * $total shared by $weights (largest remainder; ties to the first), the
     * parts adding up to $total exactly. Zero weights share equally.
     *
     * @param  list<BigInteger>  $weights
     * @return list<BigInteger>
     */
    public static function allocate(BigInteger $total, array $weights): array
    {
        $sum = array_reduce($weights, fn (BigInteger $carry, BigInteger $w) => $carry->plus($w), BigInteger::zero());

        if ($sum->isZero()) {
            $weights = array_fill(0, count($weights), BigInteger::one());
            $sum = BigInteger::of(count($weights));
        }

        $base = [];
        $remainders = [];
        $given = BigInteger::zero();

        foreach ($weights as $index => $weight) {
            $product = $total->multipliedBy($weight);
            $base[$index] = $product->quotient($sum);
            $remainders[$index] = $product->remainder($sum);
            $given = $given->plus($base[$index]);
        }

        $left = $total->minus($given)->toInt();
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, $left) as $index) {
            $base[$index] = $base[$index]->plus(1);
        }

        return $base;
    }

    /**
     * Each group's weight (its lines' payable amounts, plus its equal share of the shared lines) and net.
     *
     * @param  list<list<string>>  $groups
     * @param  array<string, array<string, BigInteger>>  $amounts
     * @param  list<string>  $shared
     * @return array{0: list<BigInteger>, 1: list<BigInteger>}
     */
    private static function byLines(array $groups, array $amounts, array $shared): array
    {
        $weights = [];
        $nets = [];
        $count = count($groups);
        $sharedPayable = self::allocateShared($shared, $amounts, 'payable', $count);
        $sharedNet = self::allocateShared($shared, $amounts, 'net', $count);

        foreach ($groups as $index => $group) {
            $weights[$index] = $sharedPayable[$index];
            $nets[$index] = $sharedNet[$index];

            foreach ($group as $id) {
                $weights[$index] = $weights[$index]->plus($amounts[$id]['payable']);
                $nets[$index] = $nets[$index]->plus($amounts[$id]['net']);
            }
        }

        return [$weights, $nets];
    }

    /**
     * What each of $count parts takes of the shared lines' $key, line by
     * line, the leftover unit of each going to the parts in turn so no part
     * is always the one that pays it.
     *
     * @param  list<string>  $shared
     * @param  array<string, array<string, BigInteger>>  $amounts
     * @return list<BigInteger>
     */
    private static function allocateShared(array $shared, array $amounts, string $key, int $count): array
    {
        $out = array_fill(0, $count, BigInteger::zero());
        $turn = 0;

        foreach ($shared as $id) {
            $each = $amounts[$id][$key]->quotient($count);
            $left = $amounts[$id][$key]->remainder($count)->toInt();

            for ($i = 0; $i < $count; $i++) {
                $extra = $left > 0 && (($i - $turn) % $count + $count) % $count < $left ? 1 : 0;
                $out[$i] = $out[$i]->plus($each)->plus($extra);
            }

            $turn = ($turn + $left) % $count;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $amounts
     * @param  list<string>|null  $lineIds
     * @param  array{0: int, 1: int}|null  $equal  part index and count of an equal split
     * @return array<string, mixed>
     */
    private static function part(array $amounts, ?array $lineIds, ?array $equal): array
    {
        return [
            'line_ids' => $lineIds,
            'lines_minor' => $amounts['lines_minor'],
            'service_charge_minor' => $amounts['service_charge_minor'],
            'service_tax_minor' => $amounts['service_tax_minor'],
            'tip_minor' => $amounts['tip_minor'],
            'total_minor' => $amounts['total_minor'],
            'equal' => $equal === null ? null : ['index' => $equal[0], 'count' => $equal[1]],
        ];
    }
}
