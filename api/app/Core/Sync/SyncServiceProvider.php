<?php

namespace App\Core\Sync;

use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Console\SyncLagCommand;
use App\Core\Sync\Jobs\RestampItemsForTax;
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
        if ($this->app->runningInConsole()) {
            $this->commands([SyncLagCommand::class]);
        }

        // NFR-04, CP-02: tax changes re-stamp the items they make (un)sellable, after commit, in batches.
        $restamp = fn (string $tenantId, array $codes, array $categories) => RestampItemsForTax::dispatch($tenantId, array_values(array_filter($codes)), array_values(array_unique(array_filter($categories))))->afterCommit();
        TaxRate::saved(fn (TaxRate $rate) => $restamp($rate->tenant_id, [$rate->tax_code_id], []));
        TaxRate::deleted(fn (TaxRate $rate) => $restamp($rate->tenant_id, [$rate->tax_code_id], []));
        TaxCode::updated(fn (TaxCode $code) => $code->wasChanged('archived_at') ? $restamp($code->tenant_id, [$code->id], []) : null);
        TaxCategoryCode::saved(fn (TaxCategoryCode $link) => $restamp($link->tenant_id, [], [$link->tax_category_id, $link->getOriginal('tax_category_id')]));
        TaxCategoryCode::deleted(fn (TaxCategoryCode $link) => $restamp($link->tenant_id, [], [$link->tax_category_id]));

        // Per device: a till syncing every few seconds stays well inside it.
        RateLimiter::for('device-sync', fn (Request $request) => Limit::perMinute((int) config('sync.requests_per_minute', 120))
            ->by('device|'.($request->user() instanceof Device ? $request->user()->id : $request->ip())));

        // AUTH-06, AUTH-08: secret rotation steps, few per device.
        RateLimiter::for('device-secret', fn (Request $request) => Limit::perHour((int) config('sync.secret_rotations_per_hour', 5) * 3)
            ->by('device-secret|'.($request->user() instanceof Device ? $request->user()->id : $request->ip())));
    }
}
