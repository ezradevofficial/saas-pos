<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * API-02 (OAuth, docs/adr/024-marketplace.md): Passport's codes and tokens
 * live in the tenant of the install, under row-level security, keyed by
 * sha256 of their identifier. The token endpoint and the resource guard
 * run before any tenant is known: `oauth_token_tenant()` (security
 * definer, ADR 002) gives the tenant of a hashed identifier, nothing more;
 * the row is then read in that tenant's context.
 */
class OAuthTokens
{
    public const AUTH_CODE = 'auth_code';

    public const ACCESS_TOKEN = 'access_token';

    public const REFRESH_TOKEN = 'refresh_token';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function hash(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    /**
     * Enter the tenant that holds the code or token $identifier. False (and
     * no tenant) when no tenant holds it, or the tenant in context already
     * is another one: a token never moves a request between tenants.
     */
    public function enter(string $kind, string $identifier): bool
    {
        $tenantId = DB::connection(TenantContext::CONNECTION)
            ->selectOne('select public.oauth_token_tenant(?, ?) as id', [$kind, self::hash($identifier)])?->id;

        if ($tenantId === null) {
            return false;
        }

        $current = $this->tenants->id();

        if ($current !== null && $current !== $tenantId) {
            return false;
        }

        $this->tenants->set($tenantId);

        return true;
    }

    /**
     * The active install of the app whose client is $clientId, acting as
     * principal $userId, in the current tenant; null when the app was
     * uninstalled or suspended, or the tenant is not active.
     */
    public function installation(string $clientId, ?string $userId): ?AppInstallation
    {
        if ($userId === null || $this->tenants->id() === null) {
            return null;
        }

        $installation = AppInstallation::query()->where('principal_id', $userId)->where('status', AppInstallation::ACTIVE)->first();
        $app = $installation === null ? null : MarketplaceApp::query()->find($installation->app_id);

        if ($app === null || $app->client_id !== $clientId || ! $app->serves()
            // A revoked client (a connector set up again, or switched off) stops its tokens too.
            || ! OAuthClient::query()->whereKey($clientId)->where('revoked', false)->exists()
            || Tenant::query()->whereKey($this->tenants->id())->value('status') !== 'active') {
            return null;
        }

        return $installation->setRelation('app', $app);
    }

    /**
     * MKT-02: revoke every code and token of an install (uninstall, or a
     * refresh token used twice). Returns how many access tokens were live.
     */
    public function revokeAll(string $clientId, string $userId): int
    {
        $tokens = OAuthAccessToken::query()->where('client_id', $clientId)->where('user_id', $userId);
        OAuthRefreshToken::query()->whereIn('access_token_id', (clone $tokens)->select('id'))->where('revoked', false)->update(['revoked' => true]);
        OAuthAuthCode::query()->where('client_id', $clientId)->where('user_id', $userId)->where('revoked', false)->update(['revoked' => true]);

        return (clone $tokens)->where('revoked', false)->update(['revoked' => true, 'updated_at' => now()]);
    }
}
