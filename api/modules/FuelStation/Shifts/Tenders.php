<?php

namespace Modules\FuelStation\Shifts;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * FUEL-12: the tender kinds of a shift's collections and the fuel share of a
 * sale's payments. Pure and exact (integers, minor units).
 */
final class Tenders
{
    public const KINDS = ['cash', 'mobile_money', 'card', 'credit', 'voucher'];

    /** A payment method type of the sale → the shift's tender kind. */
    public static function kindOf(string $methodType): string
    {
        return match ($methodType) {
            'cash' => 'cash',
            'mobile_money' => 'mobile_money',
            'card', 'bank_transfer' => 'card',
            'credit', 'room_charge' => 'credit',
            'voucher', 'fuel_voucher', 'points' => 'voucher',
            default => 'card',
        };
    }

    /**
     * What the sale's payments came to per kind, net of the change given
     * (change comes out of cash; with no cash it comes off the largest kind),
     * all in the sale currency.
     *
     * @param  list<array{method_type: string, amount_in_sale_minor: string}>  $payments
     * @return array<string, BigInteger>
     */
    public static function net(array $payments, string $changeInSaleMinor): array
    {
        $kinds = [];

        foreach ($payments as $payment) {
            $kind = self::kindOf((string) $payment['method_type']);
            $kinds[$kind] = ($kinds[$kind] ?? BigInteger::zero())->plus((string) $payment['amount_in_sale_minor']);
        }

        $change = BigInteger::of($changeInSaleMinor);

        if ($change->isPositive() && $kinds !== []) {
            $from = isset($kinds['cash']) ? 'cash' : self::largest($kinds);
            $kinds[$from] = $kinds[$from]->minus($change);
        }

        return $kinds;
    }

    /**
     * The fuel share of the net payments: each kind scaled by fuel / sale
     * total, half up, with the difference to the exact share put on the
     * largest kind so the parts add up. A sale of fuel only keeps its
     * payments as they are.
     *
     * @param  array<string, BigInteger>  $net
     * @return array<string, BigInteger>
     */
    public static function fuelShare(array $net, BigInteger $fuelTotal, BigInteger $saleTotal): array
    {
        if ($saleTotal->isZero() || $fuelTotal->isGreaterThanOrEqualTo($saleTotal)) {
            return $net;
        }

        $shares = [];
        $sum = BigInteger::zero();
        $netSum = BigInteger::zero();

        foreach ($net as $kind => $amount) {
            $shares[$kind] = $amount->multipliedBy($fuelTotal)->dividedBy($saleTotal, RoundingMode::HalfUp);
            $sum = $sum->plus($shares[$kind]);
            $netSum = $netSum->plus($amount);
        }

        if ($shares !== []) {
            $target = $netSum->multipliedBy($fuelTotal)->dividedBy($saleTotal, RoundingMode::HalfUp);
            $largest = self::largest($shares);
            $shares[$largest] = $shares[$largest]->plus($target->minus($sum));
        }

        return $shares;
    }

    /** @param array<string, BigInteger> $amounts */
    private static function largest(array $amounts): string
    {
        $key = array_key_first($amounts);

        foreach ($amounts as $kind => $amount) {
            if ($amount->isGreaterThan($amounts[$key])) {
                $key = $kind;
            }
        }

        return (string) $key;
    }
}
