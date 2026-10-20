<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Currency\Money;
use JsonSerializable;

/** One tax code's share of a line (MD-03): the rate applied (null when exempt), the amount, and whether the rate is researched (CP-01). */
final class TaxAmount implements JsonSerializable
{
    public function __construct(
        public readonly string $taxCodeId,
        public readonly string $code,
        public readonly string $kind,
        public readonly ?string $rate,
        public readonly Money $amount,
        // CP-01: the rate comes from public research, not yet confirmed.
        public readonly bool $researched = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'tax_code_id' => $this->taxCodeId,
            'code' => $this->code,
            'kind' => $this->kind,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'researched' => $this->researched,
        ];
    }
}
