<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Marketplace\Models\AppInstallation;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/**
 * MKT-02: the token response also names the install (`installation_id`),
 * which the app's webhook deliveries carry in their URL, so an app tells
 * its tenants apart without another call.
 */
class AppBearerTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $userId = $accessToken->getUserIdentifier();
        $installation = $userId === null ? null : AppInstallation::query()->where('principal_id', $userId)->where('status', AppInstallation::ACTIVE)->value('id');

        return $installation === null ? [] : ['installation_id' => $installation];
    }
}
