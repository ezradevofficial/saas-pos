<?php

namespace App\Core\Marketplace\OAuth\Repositories;

use App\Core\Api\ApiScopes;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\OAuth\OAuthClient;
use Laravel\Passport\Bridge\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * API-02 (OAuth): OAuth scopes are the public API's scopes
 * (`module.resource.read|write`, ADR 020). A token carries at most what
 * the tenant consented to for the install (or, before the install exists,
 * what the app's listed version asks for). What the token may do is
 * decided again on every request from the install's consented scopes and
 * its principal's role (OAuthAuthenticator, ScopeResolver).
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(private readonly ApiScopes $scopes) {}

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return $this->scopes->isScope($identifier) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        $allowed = $this->allowed($clientEntity->getIdentifier(), $userIdentifier);

        return array_values(array_filter($scopes, fn (ScopeEntityInterface $scope) => in_array($scope->getIdentifier(), $allowed, true)));
    }

    /** @return list<string> */
    private function allowed(string $clientId, ?string $userId): array
    {
        $installation = $userId === null ? null : AppInstallation::query()->where('principal_id', $userId)->where('status', AppInstallation::ACTIVE)->first();

        if ($installation !== null) {
            return $installation->scopeList();
        }

        $app = OAuthClient::query()->find($clientId)?->app_id;
        $version = $app === null ? null : MarketplaceApp::query()->with('listedVersion')->find($app)?->listedVersion;

        return $version?->scopeList() ?? [];
    }
}
