<?php

namespace App\Core\Marketplace\OAuth\Repositories;

use App\Core\Marketplace\OAuth\OAuthAccessToken;
use App\Core\Marketplace\OAuth\OAuthRefreshToken;
use App\Core\Marketplace\OAuth\OAuthTokens;
use Laravel\Passport\Bridge\RefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * API-02 (OAuth): refresh tokens rotate. Each is used once (Passport
 * revokes it and the access token it came with when a new pair is
 * issued). A refresh token presented again after it was used is a sign it
 * was copied: every code and token of that install is revoked (OAuth 2.0
 * security best current practice, refresh token reuse detection).
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private readonly OAuthTokens $tokens) {}

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshToken;
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        OAuthRefreshToken::query()->create([
            'id' => OAuthTokens::hash($refreshTokenEntity->getIdentifier()),
            'access_token_id' => OAuthTokens::hash($refreshTokenEntity->getAccessToken()->getIdentifier()),
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        OAuthRefreshToken::query()->whereKey(OAuthTokens::hash($tokenId))->update(['revoked' => true]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        if (! $this->tokens->enter(OAuthTokens::REFRESH_TOKEN, $tokenId)) {
            return true;
        }

        // Claimed once: two refreshes racing for one token never both pass (security review).
        $claimed = OAuthRefreshToken::query()->whereKey(OAuthTokens::hash($tokenId))->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->update(['revoked' => true]);

        if ($claimed === 1) {
            return false;
        }

        $token = OAuthRefreshToken::query()->find(OAuthTokens::hash($tokenId));

        if ($token === null) {
            return true;
        }

        if ($token->revoked) {
            $access = OAuthAccessToken::query()->find($token->access_token_id);

            if ($access !== null && $access->user_id !== null) {
                $this->tokens->revokeAll($access->client_id, $access->user_id);
            }

            return true;
        }

        return true;
    }
}
