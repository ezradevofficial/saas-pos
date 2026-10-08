<?php

namespace App\Core\Currency\Http\Requests\Concerns;

use App\Core\Currency\Models\TenantCurrency;
use Closure;

/** CUR-03: a `BASE/QUOTE` pair of two different active tenant currencies. */
trait ValidatesPair
{
    protected function activePair(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            [$base, $quote] = explode('/', (string) $value) + [null, null];

            if ($base === $quote) {
                $fail(__('core.exchange_rate.same_currency'));

                return;
            }

            $active = TenantCurrency::query()->whereIn('code', [$base, $quote])->where('active', true)->count();

            if ($active !== 2) {
                $fail(__('core.currency.not_active'));
            }
        };
    }
}
