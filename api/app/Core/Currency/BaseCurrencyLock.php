<?php

namespace App\Core\Currency;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * CUR-02: a company's base currency is fixed by its first posting.
 *
 * Contract for posting modules: call lock() inside the posting's
 * transaction, BEFORE computing base-currency amounts, and use the code it
 * returns as the base currency (never the one on a Company instance you
 * already hold, which may be stale). lock() re-reads the company row with
 * `SELECT ... FOR UPDATE`, so a concurrent base change (which takes the
 * same row lock) either committed before it, and its new base is returned,
 * or waits until the posting commits, and then finds the base locked.
 * Idempotent: the first posting's time stays. Audited as a company update.
 */
class BaseCurrencyLock
{
    /** @return string the locked base currency the posting must use */
    public function lock(Company $company): string
    {
        // A savepoint when nested: the row lock is held until the caller's
        // (outermost) transaction ends.
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company) {
            $locked = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->base_currency_locked_at === null) {
                $locked->forceFill(['base_currency_locked_at' => now()])->save();
            }

            $company->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked->base_currency;
        });
    }

    public function isLocked(Company $company): bool
    {
        return $company->base_currency_locked_at !== null;
    }

    /** Refuse (422 `base_currency_locked`) a change of a locked base currency. */
    public function assertCanChange(Company $company, string $code): void
    {
        if ($this->isLocked($company) && $company->base_currency !== $code) {
            throw new ApiException(422, 'base_currency_locked', __('core.currency.base_currency_locked'));
        }
    }
}
