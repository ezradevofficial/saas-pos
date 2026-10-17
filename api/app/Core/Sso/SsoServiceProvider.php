<?php

namespace App\Core\Sso;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * AUTH-11, ADR 023: enterprise single sign-on (OIDC with Entra ID and
 * Google Workspace, SAML 2.0), per-tenant connections and email domains.
 */
class SsoServiceProvider extends ServiceProvider
{
    /** Start, callbacks and completion: per address a minute. */
    public const LIMITER = 'sso';

    public function boot(): void
    {
        RateLimiter::for(self::LIMITER, fn (Request $request) => Limit::perMinute((int) config('sso.start_per_minute', 20))->by('sso|'.$request->ip()));
    }
}
