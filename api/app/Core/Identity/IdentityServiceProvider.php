<?php

namespace App\Core\Identity;

use App\Core\Identity\Contracts\ServiceTokenable;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\NullSmsSender;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Platform\SupportAccess\SupportAccess;
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

            // Keep in step with SmsSender::DRIVERS (app:preflight warns on any other name).
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

            // RBAC-13 (review M8): an expired support session's token is refused and logged.
            if (! $isValid && ($principal = $token->tokenable) instanceof User && $principal->support_grant_id !== null) {
                app(SupportAccess::class)->acceptsToken($principal);
                app(TenantContext::class)->set(null);

                return false;
            }

            $valid = match (true) {
                // RBAC-13f: a support session's token works only while its grant is approved and not past its end.
                $tokenable instanceof User && $tokenable->support_grant_id !== null => app(SupportAccess::class)->acceptsToken($tokenable),
                // ADR 020: an API key's principal acts through its key only, never a token.
                $tokenable instanceof User => $tokenable->isActive() && $tokenable->isPerson()
                    && ! SessionTimeout::isIdle($token, Tenant::find($token->tenant_id)),
                $tokenable instanceof Device => $tokenable->isActive(),
                // HR-24: a module's own machine (biometric terminals), while it accepts tokens.
                $tokenable instanceof ServiceTokenable => $tokenable->acceptsTokens(),
                default => false,
            };

            if (! $valid) {
                app(TenantContext::class)->set(null);
            }

            return $valid;
        });

        LoginThrottle::registerLimiters();

        // NOT-02, ADR 009: invitations and new-device alerts go through the Notifier.
        IdentityNotices::register($this->app->make(EventTypes::class));
    }
}
