<?php

namespace App\Core\Identity;

use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SmsSender::class, LogSmsSender::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // AUTH-09: a token is refused once idle beyond the tenant's timeout
        // or when its user is no longer active. findToken() has already set
        // the token's tenant; a refused token leaves no tenant behind.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $valid = $isValid
                && $token->tokenable instanceof User
                && $token->tokenable->isActive()
                && ! SessionTimeout::isIdle($token, Tenant::find($token->tenant_id));

            if (! $valid) {
                app(TenantContext::class)->set(null);
            }

            return $valid;
        });

        LoginThrottle::registerLimiters();
    }
}
