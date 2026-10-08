<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Currency\Money;
use JsonSerializable;

/** A line's net, taxes (one per code, in the order given) and gross: net + Σ tax = gross, exactly. */
final class TaxLineResult implements JsonSerializable
{
    /** @param list<TaxAmount> $tax */
    public function __construct(
        public readonly Money $net,
        public readonly array $tax,
        public readonly Money $gross,
    ) {}

    public function totalTax(): Money
    {
        return array_reduce($this->tax, fn (Money $sum, TaxAmount $tax) => $sum->plus($tax->amount), Money::ofMinor(0, $this->net->currency()));
    }

    public function jsonSerialize(): array
    {
        return ['net' => $this->net, 'tax' => $this->tax, 'gross' => $this->gross];
    }
}
