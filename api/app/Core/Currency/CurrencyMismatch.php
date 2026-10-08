<?php

namespace App\Core\Currency;

use LogicException;

/** Arithmetic between amounts of two currencies (convert first: CUR-03, CUR-04). */
class CurrencyMismatch extends LogicException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot combine {$a} and {$b} amounts without a conversion.");
    }
}
