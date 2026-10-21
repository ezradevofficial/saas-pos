<?php

namespace App\Core\Marketplace\OAuth;

/** API-02 (OAuth): an access token (a signed JWT the app holds; this row is its revocation state). */
class OAuthAccessToken extends OAuthToken
{
    public $timestamps = true;

    protected $table = 'oauth_access_tokens';
}
