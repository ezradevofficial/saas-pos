<?php

namespace App\Core\Identity;

use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\NullSmsSender;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The log driver only where messages may land in a log (local,
        // testing) or when chosen explicitly (refused elsewhere by
        // EnvironmentGuard). Anywhere else without a provider, sending throws.
        $this->app->bindIf(SmsSender::class, function ($app) {
            $driver = config('services.sms.driver') ?? ($app->environment(['local', 'testing']) ? 'log' : null);

            return match ($driver) {
                'log' => new LogSmsSender,
                default => new NullSmsSender,
            };
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // AUTH-09: a person's token is refused once idle beyond the tenant's
        // timeout or when its user is no longer active. TEN-05: a POS device
        // must work for days, so its token never idles out; it is refused
        // once the device is suspended or unpaired. findToken() has already
        // set the token's tenant; a refused token leaves no tenant behind.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $tokenable = $isValid ? $token->tokenable : null;

            $valid = match (true) {
                $tokenable instanceof User => $tokenable->isActive()
                    && ! SessionTimeout::isIdle($token, Tenant::find($token->tenant_id)),
                $tokenable instanceof Device => $tokenable->isActive(),
                default => false,
            };

            if (! $valid) {
                app(TenantContext::class)->set(null);
            }

            return $valid;
        });

        LoginThrottle::registerLimiters();
    }
}
