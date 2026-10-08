<?php

namespace App\Core\Currency;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;

/**
 * CUR-02: a company's base currency is fixed by its first posting. Posting
 * modules call lock() in the posting's transaction; it is idempotent (the
 * first posting's time stays) and audited as a company update.
 */
class BaseCurrencyLock
{
    public function lock(Company $company): void
    {
        if ($company->base_currency_locked_at !== null) {
            return;
        }

        $company->forceFill(['base_currency_locked_at' => now()])->save();
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
