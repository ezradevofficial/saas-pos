<?php

namespace App\Core\Sso\Oidc;

use SocialiteProviders\Microsoft\Provider;

/** AUTH-11: Microsoft Entra ID (the SocialiteProviders Microsoft driver), see OidcDriver. */
class EntraDriver extends Provider
{
    use OidcDriver;

    protected $scopes = ['openid', 'profile', 'email'];
}
