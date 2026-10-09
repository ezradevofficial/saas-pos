<?php

namespace App\Core\Branding;

use App\Core\Branding\Console\TenantBrandingCommand;
use App\Core\Branding\Console\VerifyDomainsCommand;
use App\Core\Branding\Domains\DnsResolver;
use App\Core\Branding\Domains\SystemDnsResolver;
use App\Core\Configuration\ConfigKinds;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * BR-02..BR-08: the theme configuration kind, brand assets, the branded
 * sign-in lookup, custom domains (DNS TXT verification, the TLS ask
 * endpoint) and the branded email sender.
 */
class BrandingServiceProvider extends ServiceProvider
{
    public const PUBLIC_LIMITER = 'branding-public';

    public const TLS_LIMITER = 'tls-ask';

    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        ThemeKind::register($this->app->make(ConfigKinds::class));

        RateLimiter::for(self::PUBLIC_LIMITER, fn (Request $request) => Limit::perMinute((int) config('branding.public_per_minute', 60))->by('ip|'.$request->ip()));
        RateLimiter::for(self::TLS_LIMITER, fn (Request $request) => Limit::perMinute((int) config('branding.domains.tls_ask_per_minute', 120))->by('ip|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyDomainsCommand::class, TenantBrandingCommand::class]);
        }
    }
}
