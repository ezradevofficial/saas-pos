<?php

namespace App\Core\Marketplace\OAuth\Repositories;

use App\Core\Marketplace\OAuth\OAuthAuthCode;
use App\Core\Marketplace\OAuth\OAuthTokens;
use Laravel\Passport\Bridge\AuthCode;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * API-02 (OAuth): authorization codes in the installing tenant, keyed by
 * sha256 of their identifier. Issued during consent (the tenant is the
 * signed-in user's); exchanged at the token endpoint, which first enters
 * the code's tenant (OAuthTokens::enter).
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(private readonly OAuthTokens $tokens) {}

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode;
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        OAuthAuthCode::query()->create([
            'id' => OAuthTokens::hash($authCodeEntity->getIdentifier()),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode(array_map(fn ($scope) => $scope->getIdentifier(), $authCodeEntity->getScopes())),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        OAuthAuthCode::query()->whereKey(OAuthTokens::hash($codeId))->update(['revoked' => true]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        if (! $this->tokens->enter(OAuthTokens::AUTH_CODE, $codeId)) {
            return true;
        }

        // Claimed once: two exchanges racing for one code never both pass (security review).
        $claimed = OAuthAuthCode::query()->whereKey(OAuthTokens::hash($codeId))->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->update(['revoked' => true]);

        return $claimed !== 1;
    }
}
