<?php

namespace App\Core\Sso\Oidc;

use Laravel\Socialite\Two\GoogleProvider;

/** AUTH-11: Google Workspace (Socialite's Google driver), see OidcDriver. */
class GoogleDriver extends GoogleProvider
{
    use OidcDriver;

    protected $scopes = ['openid', 'profile', 'email'];
}
