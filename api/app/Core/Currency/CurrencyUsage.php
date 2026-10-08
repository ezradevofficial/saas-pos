<?php

namespace App\Core\Currency;

use Closure;

/**
 * Whether the current tenant has stored any amount in a currency (CUR-01):
 * once it has, the currency's decimals are locked, since stored minor
 * units would change value. Modules that store amounts (sales, purchasing,
 * accounting...) register a checker; each runs in the tenant's context
 * (row-level security applies). Core registers one for party credit
 * limits (MasterDataServiceProvider).
 */
class CurrencyUsage
{
    /** @var list<Closure(string): bool> */
    private array $checkers = [];

    /** @param callable(string): bool $checker true when an amount in the currency code is stored */
    public function register(callable $checker): void
    {
        $this->checkers[] = Closure::fromCallable($checker);
    }

    public function isUsed(string $code): bool
    {
        foreach ($this->checkers as $checker) {
            if ($checker($code)) {
                return true;
            }
        }

        return false;
    }
}
