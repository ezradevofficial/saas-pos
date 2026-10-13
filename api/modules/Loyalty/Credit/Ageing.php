<?php

namespace Modules\Loyalty\Credit;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\CreditEntry;

/**
 * LOY-18: debt ageing. Each open charge is aged by its date (days from the
 * charge's day to the as-of day, in the company's time zone) into 0–30,
 * 31–60, 61–90 and 90+. A reversal settles the charge of its own sale
 * first; every other credit (payments, refunds, negative adjustments)
 * settles the oldest charges first.
 */
final class Ageing
{
    public const BUCKETS = ['0_30', '31_60', '61_90', '90_plus'];

    /**
     * @param  Collection<int, CreditEntry>  $entries  the account's entries up to the as-of moment
     * @return array{buckets: array<string, string>, open_minor: string, credit_minor: string}
     */
    public static function of(Collection $entries, CarbonImmutable $asOf, string $timeZone): array
    {
        $charges = [];
        $credits = BigInteger::zero();
        $reversals = [];

        foreach ($entries->sortBy([['occurred_at', 'asc'], ['id', 'asc']]) as $entry) {
            $amount = BigInteger::of((string) $entry->amount_minor);

            if ($amount->isPositive()) {
                $charges[] = ['sale_id' => $entry->sale_id, 'date' => $entry->occurred_at->setTimezone($timeZone)->format('Y-m-d'), 'open' => $amount];
            } elseif ($entry->kind === 'reverse_charge' && $entry->sale_id !== null) {
                $reversals[] = ['sale_id' => $entry->sale_id, 'amount' => $amount->negated()];
            } else {
                $credits = $credits->plus($amount->negated());
            }
        }

        // A reversal settles its own sale's charge first; what is left of it settles the oldest.
        foreach ($reversals as $reversal) {
            $left = $reversal['amount'];

            foreach ($charges as $i => $charge) {
                if ($left->isZero() || $charge['sale_id'] !== $reversal['sale_id']) {
                    continue;
                }

                $take = BigInteger::min($left, $charge['open']);
                $charges[$i]['open'] = $charge['open']->minus($take);
                $left = $left->minus($take);
            }

            $credits = $credits->plus($left);
        }

        foreach ($charges as $i => $charge) {
            if ($credits->isZero()) {
                break;
            }

            $take = BigInteger::min($credits, $charge['open']);
            $charges[$i]['open'] = $charge['open']->minus($take);
            $credits = $credits->minus($take);
        }

        $buckets = array_fill_keys(self::BUCKETS, BigInteger::zero());
        $today = CarbonImmutable::parse($asOf->setTimezone($timeZone)->format('Y-m-d'), $timeZone);

        foreach ($charges as $charge) {
            if ($charge['open']->isZero()) {
                continue;
            }

            $days = (int) CarbonImmutable::parse($charge['date'], $timeZone)->diffInDays($today, false);
            $bucket = match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$bucket] = $buckets[$bucket]->plus($charge['open']);
        }

        $open = array_reduce($buckets, fn (BigInteger $sum, BigInteger $value) => $sum->plus($value), BigInteger::zero());

        return [
            'buckets' => array_map('strval', $buckets),
            'open_minor' => (string) $open,
            // More paid than charged: the customer's credit.
            'credit_minor' => (string) $credits,
        ];
    }
}
