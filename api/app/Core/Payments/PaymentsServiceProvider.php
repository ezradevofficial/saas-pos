<?php

namespace App\Core\Payments;

use App\Core\Payments\Console\ProcessPaymentTimersCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Payments at the till (concept note 7.1): provider adapters
 * (PaymentProviderRegistry; modules may register more), payment intents,
 * provider callbacks and the timeout scan. Routes are in routes/api.php.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public const CALLBACK_LIMITER = 'payment-callbacks';

    public function register(): void
    {
        $this->app->singleton(PaymentProviderRegistry::class);
    }

    public function boot(): void
    {
        // Provider callbacks are public: 600 a minute per address.
        RateLimiter::for(self::CALLBACK_LIMITER, fn (Request $request) => Limit::perMinute(600)->by('ip|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessPaymentTimersCommand::class]);
        }
    }
}
