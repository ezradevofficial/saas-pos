<?php

namespace App\Core\Marketplace\OAuth;

/** API-02 (OAuth): a refresh token; used once (rotated), then revoked. */
class OAuthRefreshToken extends OAuthToken
{
    protected $table = 'oauth_refresh_tokens';
}
