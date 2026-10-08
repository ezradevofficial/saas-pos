<?php

namespace App\Core\Numbering;

/** A number issued for one document (NUM-01): the counter value, its period and the formatted number. */
final class IssuedNumber
{
    public function __construct(
        public readonly int $value,
        public readonly string $period,
        public readonly string $number,
        public readonly string $formatId,
    ) {}
}
