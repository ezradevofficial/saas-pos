<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Api\ApiKeyContext;
use App\Core\Api\ApiScopes;
use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditContext;
use App\Core\Http\ApiErrorRenderer;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * API-02 (OAuth), MKT-02 (docs/adr/024-marketplace.md): an app's access
 * token, the same as an API key from here on.
 *
 * 1. The JWT's signature and expiry are checked (Passport's resource
 *    server); its row is found in the install's tenant through
 *    `oauth_token_tenant()` (AccessTokenRepository), which enters that
 *    tenant, and must not be revoked.
 * 2. The install must be active, its app serving (listed, not suspended)
 *    and the tenant active; the principal a service user.
 * 3. The install's key (kind `app`, its consented scopes) becomes the
 *    request's key: the `public-api` routes, scopes, rate limits, usage,
 *    idempotency and audit work exactly as for an API key. On any other
 *    route the token is refused (403 `api_key_route_refused`).
 *
 * Any failure leaves no tenant behind and answers null (401).
 */
class OAuthAuthenticator
{
    private const FAILED = 'oauth_authentication_failed';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApiKeyContext $context,
        private readonly AuditContext $audit,
        private readonly OAuthTokens $tokens,
    ) {}

    /** Whether a bearer token is an app's access token (a JWT); API keys and personal tokens never are. */
    public static function claims(?string $bearer): bool
    {
        return is_string($bearer) && preg_match('/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $bearer) === 1;
    }

    public function authenticate(Request $request): ?User
    {
        $failures = 'oauth-failures|'.$request->ip();
        $max = max(1, (int) config('api.rate_limits.failed_auth_per_ip', 20));

        if (RateLimiter::tooManyAttempts($failures, $max)) {
            $retry = max(1, RateLimiter::availableIn($failures));

            throw new ApiException(429, 'rate_limited', ApiErrorRenderer::retryMessage($retry), headers: ['Retry-After' => $retry]);
        }

        if ($request->attributes->get(self::FAILED) === true) {
            return null;
        }

        [$principal, $key, $installation] = $this->resolve($request) ?? [null, null, null];

        if ($principal === null) {
            $this->tenants->set(null);
            $request->attributes->set(self::FAILED, true);
            RateLimiter::hit($failures, (int) config('api.rate_limits.failed_auth_decay_seconds', 600));

            return null;
        }

        if (ApiScopes::resourceOf($request->route()) === null) {
            $this->tenants->set(null);

            throw new ApiException(403, 'api_key_route_refused', __('api.errors.route_refused'));
        }

        $this->context->set($key);
        // AUD-02: entries written by the app name its install and key (the principal is the entry's user).
        $this->audit->setMetadata(['api_key_id' => $key->id, 'app_installation_id' => $installation->id, 'app_id' => $installation->app_id]);

        return $principal->withAccessToken($key);
    }

    /** @return array{0: User, 1: ApiKey, 2: AppInstallation}|null */
    private function resolve(Request $request): ?array
    {
        if (! OAuthKeys::configured()) {
            return null;
        }

        try {
            $validated = app(ResourceServer::class)->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $clientId = (string) $validated->getAttribute('oauth_client_id');
        $userId = $validated->getAttribute('oauth_user_id');
        $installation = $this->tokens->installation($clientId, is_string($userId) ? $userId : null);

        if ($installation === null) {
            return null;
        }

        $key = ApiKey::query()->whereKey($installation->api_key_id)->first();
        $principal = User::query()->whereKey($installation->principal_id)->first();

        if ($key === null || $key->kind !== 'app' || ! $key->isUsable() || $principal?->isServicePrincipal() !== true) {
            return null;
        }

        return [$principal, $key, $installation];
    }
}
