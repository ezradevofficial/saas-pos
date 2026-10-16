<?php

namespace App\Core\Platform;

use App\Core\Identity\Services\LoginThrottle;
use App\Core\Notifications\EventTypes;
use App\Core\Platform\Console\CreatePlatformUser;
use App\Core\Platform\Console\VerifyPlatformAudit;
use App\Core\Platform\SupportAccess\Console\ExpireSupportAccess;
use App\Core\Platform\SupportAccess\SupportNotices;
use App\Core\Platform\Tenants\PlatformNotices;
use App\Core\Platform\Tenants\TenantHealthProbes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Platform staff (ADR 013): the `platform` guard over platform_access_tokens,
 * sign-in rate limits, and the public catalogue limiter (ONB-03).
 */
class PlatformServiceProvider extends ServiceProvider
{
    public const AUTH_IP_LIMITER = 'platform-auth-ip';

    public const AUTH_LOGIN_LIMITER = 'platform-auth-login';

    public const PUBLIC_CATALOGUE_LIMITER = 'catalogue-public';

    public const COUPON_LIMITER = 'catalogue-coupon';

    public function register(): void
    {
        $this->app->singleton(PlatformTokens::class);
        $this->app->singleton(TenantHealthProbes::class);
    }

    public function boot(): void
    {
        // The guard (config/auth.php `platform`) reads only staff tokens.
        Auth::viaRequest('platform-token', fn (Request $request) => $this->app->make(PlatformTokens::class)->authenticate($request));

        // AUTH-10 for staff: 10 a minute per IP, 5 a minute per email.
        RateLimiter::for(self::AUTH_IP_LIMITER, fn (Request $request) => Limit::perMinute(LoginThrottle::PER_IP_PER_MINUTE)->by('platform-ip|'.$request->ip()));
        RateLimiter::for(self::AUTH_LOGIN_LIMITER, fn (Request $request) => Limit::perMinute(LoginThrottle::PER_LOGIN_PER_MINUTE)
            ->by('platform-login|'.mb_strtolower(trim((string) $request->input('email', '')))));

        // Quotes carrying a coupon code: 5 a minute per IP and 100 a minute in all, so codes cannot be guessed.
        RateLimiter::for(self::COUPON_LIMITER, fn (Request $request) => filled($request->input('coupon'))
            ? [
                Limit::perMinute((int) config('platform.coupon_per_minute', 5))->by('coupon-ip|'.$request->ip()),
                Limit::perMinute((int) config('platform.coupon_global_per_minute', 100))->by('coupon-all'),
            ]
            : Limit::none());

        RateLimiter::for(self::PUBLIC_CATALOGUE_LIMITER, fn (Request $request) => Limit::perMinute((int) config('platform.public_per_minute', 60))->by('catalogue|'.$request->ip()));

        // ADM-01c: what staff did to the account, told to the tenant's Owners.
        PlatformNotices::register($this->app->make(EventTypes::class));
        // RBAC-13b: support access told to the tenant's Owners and Admins.
        SupportNotices::register($this->app->make(EventTypes::class));

        // RBAC-13f: approvals past their end are closed every five minutes.
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('support:expire')->everyFiveMinutes()->withoutOverlapping());

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformUser::class, VerifyPlatformAudit::class, ExpireSupportAccess::class]);
        }
    }
}
