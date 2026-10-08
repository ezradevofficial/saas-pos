<?php

namespace App\Core\Currency\Tender;

use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use JsonSerializable;

/**
 * CUR-06: what a set of tenders settles.
 *
 * - paidInDue: the tenders converted into the due currency, rounded down to
 *   its minor unit (the customer is credited no more than they paid).
 * - remaining: due minus paidInDue, never negative.
 * - change: in the change currency, rounded down to its cash rounding.
 * - overpaid: paidInDue is above the amount due.
 * - roundingMinor: what the shop keeps from rounding the change down, in
 *   minor units of the due currency (for later accounting).
 */
final class TenderResult implements JsonSerializable
{
    /**
     * @param  list<array{tender: TenderLine, in_due: Money, rate: ?Rate}>  $lines
     */
    public function __construct(
        public readonly Money $due,
        public readonly Money $paidInDue,
        public readonly Money $remaining,
        public readonly Money $change,
        public readonly bool $overpaid,
        public readonly string $roundingMinor,
        public readonly array $lines,
    ) {}

    public function isSettled(): bool
    {
        return $this->remaining->isZero();
    }

    public function jsonSerialize(): array
    {
        return [
            'due' => $this->due,
            'paid_in_due' => $this->paidInDue,
            'remaining' => $this->remaining,
            'change' => $this->change,
            'overpaid' => $this->overpaid,
            'rounding_minor' => $this->roundingMinor,
            'lines' => array_map(fn (array $line) => [
                'method' => $line['tender']->method,
                'amount' => $line['tender']->amount,
                'in_due' => $line['in_due'],
                'rate' => $line['rate']?->toArray(),
            ], $this->lines),
        ];
    }
}
