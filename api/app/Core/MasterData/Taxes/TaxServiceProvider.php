<?php

namespace App\Core\MasterData\Taxes;

use App\Core\CountryPacks\Console\PublishCountryPack;
use App\Core\CountryPacks\CountryPacks;
use App\Core\MasterData\Taxes\Listeners\CopyCountryPackTaxCodes;
use App\Core\Tenancy\Events\CompanyCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** MD-03, CP-01..CP-03: country packs, tax codes and rates, the tax calculator, price lists. */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CountryPacks::class);
        $this->app->singleton(ApplyCountryPack::class);
        $this->app->singleton(TaxRates::class);
        $this->app->singleton(TaxCalculator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PublishCountryPack::class]);
        }

        Event::listen(CompanyCreated::class, CopyCountryPackTaxCodes::class);
    }
}
