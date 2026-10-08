<?php

namespace App\Core\Currency;

use App\Core\Currency\Console\SyncCurrencies;
use Illuminate\Support\ServiceProvider;

/** CUR-01, CUR-02: the currency catalogue, tenant currencies, Money. */
class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Currencies::class);
        $this->app->singleton(CurrencyDecimals::class);
        $this->app->singleton(CurrencyUsage::class);
        $this->app->singleton(TenantCurrencies::class);
        $this->app->singleton(BaseCurrencyLock::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncCurrencies::class]);
        }
    }
}
