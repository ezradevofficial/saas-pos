<?php

namespace App\Core\Currency;

use App\Core\Currency\Console\FetchExchangeRates;
use App\Core\Currency\Console\SyncCurrencies;
use App\Core\Currency\Feeds\RateFeeds;
use App\Core\Currency\Tender\TenderCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/** CUR-01..CUR-08: currencies, Money, exchange rates, conversion, tender. */
class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Currencies::class);
        $this->app->singleton(CurrencyDecimals::class);
        $this->app->singleton(CurrencyUsage::class);
        $this->app->singleton(TenantCurrencies::class);
        $this->app->singleton(BaseCurrencyLock::class);
        $this->app->singleton(CashRounding::class);
        $this->app->singleton(ExchangeRates::class);
        $this->app->singleton(Converter::class);
        $this->app->singleton(TenderCalculator::class);
        $this->app->singleton(RateFeeds::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncCurrencies::class, FetchExchangeRates::class]);
        }

        $this->registerMigrationMacros();
    }

    /**
     * ADR 003, CUR-04: money and FX snapshot columns for documents.
     *
     *     $table->money('total');          // total_minor bigint, total_currency char(3)
     *     $table->money('tip', nullable: true);
     *     $table->fxSnapshot('fx');        // fx_rate numeric(18,8), fx_base_currency char(3),
     *                                      // fx_rate_kind varchar(10), fx_rate_effective_at timestamptz (all null)
     */
    private function registerMigrationMacros(): void
    {
        Blueprint::macro('money', function (string $name, bool $nullable = false): void {
            /** @var Blueprint $this */
            $this->bigInteger("{$name}_minor")->nullable($nullable);
            $this->char("{$name}_currency", 3)->nullable($nullable);
        });

        Blueprint::macro('fxSnapshot', function (string $prefix = 'fx'): void {
            /** @var Blueprint $this */
            $this->decimal("{$prefix}_rate", 18, 8)->nullable();
            $this->char("{$prefix}_base_currency", 3)->nullable();
            $this->string("{$prefix}_rate_kind", 10)->nullable();
            $this->timestampTz("{$prefix}_rate_effective_at")->nullable();
        });
    }
}
