<?php

namespace App\Core\Marketplace\OAuth;

/** API-02 (OAuth): an authorization code (10 minutes, used once). */
class OAuthAuthCode extends OAuthToken
{
    protected $table = 'oauth_auth_codes';
}
