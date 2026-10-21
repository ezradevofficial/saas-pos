<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Marketplace\Http\Requests\OAuthTokenRequest;
use App\Core\Marketplace\OAuth\OAuthKeys;
use App\Core\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Response as PsrResponse;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-02 (OAuth): `POST /api/v1/oauth/token`, Passport's authorization
 * server for the authorization code (with the PKCE verifier) and refresh
 * token grants. The code's or refresh token's tenant is entered from its
 * hashed identifier (OAuthTokens), and left at the end. Errors are in the
 * OAuth format (RFC 6749 5.2); answers are never cached.
 */
class TokenController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly TenantContext $tenants,
    ) {}

    public function issue(OAuthTokenRequest $request): Response
    {
        OAuthKeys::assertConfigured();

        try {
            $psr = $this->server->respondToAccessTokenRequest((new PsrHttpFactory)->createRequest($request), new PsrResponse);
        } catch (OAuthServerException $e) {
            $psr = $e->generateHttpResponse(new PsrResponse);
        } finally {
            $this->tenants->set(null);
        }

        $response = (new HttpFoundationFactory)->createResponse($psr);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
