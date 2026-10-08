<?php

namespace App\Core\Sync;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Sources\CurrencySource;
use App\Core\Sync\Sources\CustomerSource;
use App\Core\Sync\Sources\ExchangeRateSource;
use App\Core\Sync\Sources\ItemCategorySource;
use App\Core\Sync\Sources\ItemSource;
use App\Core\Sync\Sources\PaymentMethodSource;
use App\Core\Sync\Sources\PriceListSource;
use App\Core\Sync\Sources\SettingsSource;
use App\Core\Sync\Sources\StaffSource;
use App\Core\Sync\Sources\TaxCategorySource;
use App\Core\Sync\Sources\TaxCodeSource;
use App\Core\Sync\Sources\UomSource;
use App\Core\Tenancy\Models\Device;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * NFR-04, ADR 004: POS master data sync. Core registers its entities here,
 * in the order a new device pulls them (settings and reference data first,
 * then the catalogue, customers and staff). Modules add theirs to
 * SyncSources from their own providers.
 */
class SyncServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public const CORE_SOURCES = [
        SettingsSource::class,
        CurrencySource::class,
        ExchangeRateSource::class,
        TaxCodeSource::class,
        TaxCategorySource::class,
        PriceListSource::class,
        PaymentMethodSource::class,
        UomSource::class,
        ItemCategorySource::class,
        ItemSource::class,
        CustomerSource::class,
        StaffSource::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SyncSources::class, function ($app) {
            $sources = new SyncSources($app->make(ModuleRegistry::class));

            foreach (self::CORE_SOURCES as $class) {
                $sources->register($app->make($class));
            }

            return $sources;
        });
    }

    public function boot(): void
    {
        // Per device: a till syncing every few seconds stays well inside it.
        RateLimiter::for('device-sync', fn (Request $request) => Limit::perMinute((int) config('sync.requests_per_minute', 120))
            ->by('device|'.($request->user() instanceof Device ? $request->user()->id : $request->ip())));
    }
}
