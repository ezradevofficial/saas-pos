<?php

namespace App\Core\Marketplace\OAuth\Repositories;

use App\Core\Marketplace\OAuth\OAuthAccessToken;
use App\Core\Marketplace\OAuth\OAuthTokens;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * API-02 (OAuth): access tokens are signed JWTs the app holds; their row
 * (sha256 of the `jti`, in the install's tenant) is the revocation state.
 * A token is issued only for an active install of a serving app in an
 * active tenant: uninstalling, suspending the app or the tenant stops new
 * tokens as well as the old ones.
 */
class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(private readonly OAuthTokens $tokens) {}

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        return new Passport::$accessTokenEntity($userIdentifier, $scopes, $clientEntity);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $clientId = $accessTokenEntity->getClient()->getIdentifier();
        $userId = $accessTokenEntity->getUserIdentifier();

        if ($this->tokens->installation($clientId, $userId) === null) {
            throw OAuthServerException::accessDenied(__('marketplace.errors.not_installed'));
        }

        OAuthAccessToken::query()->create([
            'id' => OAuthTokens::hash($accessTokenEntity->getIdentifier()),
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => json_encode(array_map(fn ($scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes())),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        OAuthAccessToken::query()->whereKey(OAuthTokens::hash($tokenId))->update(['revoked' => true, 'updated_at' => now()]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        if (! $this->tokens->enter(OAuthTokens::ACCESS_TOKEN, $tokenId)) {
            return true;
        }

        $token = OAuthAccessToken::query()->find(OAuthTokens::hash($tokenId));

        return $token === null || ! $token->isUsable();
    }
}
