<?php

namespace App\Core\Marketplace\OAuth\Repositories;

use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\OAuth\OAuthClient;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\Client;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * API-02 (OAuth), ADM-05: a client is usable only while its app is listed
 * (an approved version) and not suspended, with the redirect URIs review
 * set on it. Only the authorization code and refresh token grants: no
 * client credentials, password, implicit or device grants.
 */
class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(private readonly Hasher $hasher) {}

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->find($clientIdentifier);

        return $client === null ? null : new Client(
            $client->getKey(),
            $client->name,
            $client->redirect_uris,
            $client->confidential(),
            null,
            OAuthClient::GRANTS,
        );
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = $this->find($clientIdentifier);

        if ($client === null || ! in_array($grantType, OAuthClient::GRANTS, true)) {
            return false;
        }

        return ! $client->confidential() || ($clientSecret !== null && $clientSecret !== '' && $this->hasher->check($clientSecret, $client->secret));
    }

    private function find(string $id): ?OAuthClient
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $client = OAuthClient::query()->whereKey($id)->where('revoked', false)->first();
        // Read under row-level security: a tenant (or none, at the token endpoint) sees only listed apps besides its own.
        $app = $client?->app_id === null ? null : MarketplaceApp::query()->find($client->app_id);

        return $app !== null && $app->serves() ? $client : null;
    }
}
