<?php

namespace App\Core\Configuration;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Versioned configuration (LAY-06, LAY-07): the kind registry modules
 * register into (see ConfigKinds). Core registers no kinds itself; the
 * theme editor, layout designers and templates bring theirs.
 */
class ConfigurationServiceProvider extends ServiceProvider
{
    /** The limiter on writes under config/{kind}. */
    public const WRITE_LIMITER = 'config-writes';

    /** Writes a minute per user: autosave every second still fits. */
    public const WRITES_PER_MINUTE = 60;

    public function register(): void
    {
        $this->app->singleton(ConfigKinds::class);
    }

    public function boot(): void
    {
        RateLimiter::for(self::WRITE_LIMITER, fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('user|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
