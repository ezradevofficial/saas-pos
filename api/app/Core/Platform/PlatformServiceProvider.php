<?php

namespace App\Core\Platform;

use App\Core\Identity\Services\LoginThrottle;
use App\Core\Platform\Console\CreatePlatformUser;
use App\Core\Platform\Console\VerifyPlatformAudit;
use Illuminate\Cache\RateLimiting\Limit;
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

    public function register(): void
    {
        $this->app->singleton(PlatformTokens::class);
    }

    public function boot(): void
    {
        // The guard (config/auth.php `platform`) reads only staff tokens.
        Auth::viaRequest('platform-token', fn (Request $request) => $this->app->make(PlatformTokens::class)->authenticate($request));

        // AUTH-10 for staff: 10 a minute per IP, 5 a minute per email.
        RateLimiter::for(self::AUTH_IP_LIMITER, fn (Request $request) => Limit::perMinute(LoginThrottle::PER_IP_PER_MINUTE)->by('platform-ip|'.$request->ip()));
        RateLimiter::for(self::AUTH_LOGIN_LIMITER, fn (Request $request) => Limit::perMinute(LoginThrottle::PER_LOGIN_PER_MINUTE)
            ->by('platform-login|'.mb_strtolower(trim((string) $request->input('email', '')))));

        RateLimiter::for(self::PUBLIC_CATALOGUE_LIMITER, fn (Request $request) => Limit::perMinute((int) config('platform.public_per_minute', 60))->by('catalogue|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformUser::class, VerifyPlatformAudit::class]);
        }
    }
}
