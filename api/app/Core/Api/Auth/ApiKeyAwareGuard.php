<?php

namespace App\Core\Api\Auth;

use App\Core\Api\ApiKeyAuthenticator;
use App\Core\Api\ApiKeyToken;
use Illuminate\Http\Request;
use Laravel\Sanctum\Guard;

/**
 * API-02 (ADR 020): Sanctum's guard, taught API keys. A bearer token
 * starting with `sk_` is an API key and only an API key: it is checked by
 * ApiKeyAuthenticator, never looked up as a personal access token. Every
 * other request is Sanctum's, unchanged. Every `auth:sanctum` route, in
 * core and in modules, uses it; ApiKeyAuthenticator refuses keys on routes
 * that are not public (deny by default).
 */
class ApiKeyAwareGuard extends Guard
{
    public function __invoke(Request $request)
    {
        $bearer = $request->bearerToken();

        if (ApiKeyToken::claims($bearer)) {
            return app(ApiKeyAuthenticator::class)->authenticate($request, (string) $bearer);
        }

        return parent::__invoke($request);
    }
}
