<?php

namespace App\Core\Marketplace;

use App\Core\Billing\Contracts\AddOnCharges;
use App\Core\Billing\Events\OperatorInvoicePaid;
use App\Core\Marketplace\Billing\AppBilling;
use App\Core\Marketplace\Billing\Payables;
use App\Core\Marketplace\Connectors\Courier\Couriers;
use App\Core\Marketplace\Console\ConnectorCommand;
use App\Core\Marketplace\OAuth\AppBearerTokenResponse;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Marketplace\OAuth\OAuthKeys;
use App\Core\Marketplace\OAuth\OAuthTokens;
use App\Core\Marketplace\OAuth\Repositories\AccessTokenRepository;
use App\Core\Marketplace\OAuth\Repositories\AuthCodeRepository;
use App\Core\Marketplace\OAuth\Repositories\ClientRepository;
use App\Core\Marketplace\OAuth\Repositories\RefreshTokenRepository;
use App\Core\Marketplace\OAuth\Repositories\ScopeRepository;
use App\Core\Marketplace\Webhooks\ApiKeyPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;

/**
 * The marketplace (MKT-01..MKT-04, ADM-05, API-02 OAuth;
 * docs/adr/024-marketplace.md): Passport's authorization server wired to
 * tenant-scoped, hashed token storage, the authorization code (PKCE) and
 * refresh token grants only, app installs as API-key principals, paid
 * apps on the subscription's invoices, the developers' payable ledger and
 * the first-party connectors.
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    /** The public token endpoint, per client address. */
    public const TOKEN_LIMITER = 'oauth-token';

    /** The consent screen's decisions, per user. */
    public const AUTHORIZE_LIMITER = 'oauth-authorize';

    /** @var array{private: string, public: string}|null the test process's key pair */
    private static ?array $testKeys = null;

    public function register(): void
    {
        // Passport's own routes (its Blade consent screen, client and token management) are not served:
        // the consent screen is the web app's, and only the token endpoint is public (routes/marketplace.php).
        Passport::ignoreRoutes();
        Passport::useClientModel(OAuthClient::class);

        // Codes and tokens live in the install's tenant under row-level security, keyed by hashes.
        $this->app->bind(Bridge\AuthCodeRepository::class, AuthCodeRepository::class);
        $this->app->bind(Bridge\AccessTokenRepository::class, AccessTokenRepository::class);
        $this->app->bind(Bridge\RefreshTokenRepository::class, RefreshTokenRepository::class);
        $this->app->bind(Bridge\ClientRepository::class, ClientRepository::class);
        $this->app->bind(Bridge\ScopeRepository::class, ScopeRepository::class);

        $this->app->singleton(OAuthTokens::class);
        $this->app->singleton(Couriers::class);
        // MKT-03: paid apps are lines of the subscription's invoices.
        $this->app->singleton(AddOnCharges::class, AppBilling::class);
    }

    public function boot(): void
    {
        Passport::tokensExpireIn(CarbonInterval::minutes(max(5, (int) config('marketplace.oauth.access_token_minutes', 60))));
        Passport::refreshTokensExpireIn(CarbonInterval::days(max(1, (int) config('marketplace.oauth.refresh_token_days', 30))));
        Passport::useAuthorizationServerResponseType(new AppBearerTokenResponse);

        // The suite signs with a key pair made for the process; no key is ever committed.
        if ($this->app->runningUnitTests() && ! OAuthKeys::configured()) {
            self::$testKeys ??= OAuthKeys::generate();
            config(['passport.private_key' => self::$testKeys['private'], 'passport.public_key' => self::$testKeys['public']]);
        }

        // HOOK-01, ADR 021: subscriptions owned by an API key (a marketplace install's).
        $this->callAfterResolving(WebhookPrincipals::class, fn (WebhookPrincipals $principals) => $principals->register(WebhookSubscriptions::KEY_PRINCIPAL, fn (string $id) => ApiKeyPrincipal::resolve($id)));

        // MKT-03: the developers' share of each paid app line, when its invoice is paid.
        Event::listen(OperatorInvoicePaid::class, [Payables::class, 'onInvoicePaid']);

        // Phase 8 review M5: per client id (one app refreshing for many tenants from one address
        // is not refused with every other app behind it), plus a looser ceiling per address. The
        // client's window is per address too: a client id is public, so anyone sending it from
        // elsewhere never uses up the real app's window.
        RateLimiter::for(self::TOKEN_LIMITER, fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('marketplace.oauth.token_requests_per_minute', 120)))->by('oauth-token|client|'.self::tokenClientId($request).'|'.$request->ip()),
            Limit::perMinute(max(1, (int) config('marketplace.oauth.token_requests_per_minute_per_ip', 600)))->by('oauth-token|ip|'.$request->ip()),
        ]);
        RateLimiter::for(self::AUTHORIZE_LIMITER, fn (Request $request) => Limit::perMinute(30)->by('oauth-authorize|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([ConnectorCommand::class]);
        }
    }

    /** The client a token request names (body or HTTP Basic) when it is a client id at all, else `none`. */
    private static function tokenClientId(Request $request): string
    {
        $id = $request->input('client_id', $request->getUser());

        return is_string($id) && Str::isUuid($id) ? strtolower($id) : 'none';
    }
}
