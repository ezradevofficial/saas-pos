<?php

namespace App\Core\Currency\Tender;

use App\Core\Currency\Money;
use InvalidArgumentException;

/** CUR-06: one payment towards a sale, in any active currency ($method is a label such as "cash" or "mpesa"). */
final class TenderLine
{
    public function __construct(
        public readonly Money $amount,
        public readonly ?string $method = null,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A tender amount cannot be negative.');
        }
    }
}
