<?php

namespace Modules\Ecommerce\Courier;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * MKT-04 (ADR 024): the E-commerce routes a courier uses on the public API
 * (routes/courier.php) and their rate limits, per caller (the key's or
 * install's principal, or the person) and per route, so a courier polling
 * for orders never uses up its writes. Registered after the module's own
 * provider, which binds the route models.
 */
class CourierApiServiceProvider extends ServiceProvider
{
    public const READS_LIMITER = 'ecommerce-courier-reads';

    public const WRITES_LIMITER = 'ecommerce-courier-writes';

    public function boot(): void
    {
        $by = fn (Request $request, string $name) => $name.'|'.($request->user()?->getAuthIdentifier() ?? 'ip|'.$request->ip()).'|'.($request->route()?->uri() ?? '');
        RateLimiter::for(self::READS_LIMITER, fn (Request $request) => Limit::perMinute((int) config('ecommerce.courier.reads_per_minute', 120))->by($by($request, self::READS_LIMITER)));
        RateLimiter::for(self::WRITES_LIMITER, fn (Request $request) => Limit::perMinute((int) config('ecommerce.courier.writes_per_minute', 120))->by($by($request, self::WRITES_LIMITER)));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(dirname(__DIR__).'/routes/courier.php');
        }
    }
}
