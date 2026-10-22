<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Http\ApiException;
use App\Core\Marketplace\Http\MarketplacePresenter;
use App\Core\Marketplace\Http\Requests\AuthorizeAppRequest;
use App\Core\Marketplace\Http\Requests\DecideAuthorizationRequest;
use App\Core\Marketplace\Installations;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Marketplace\OAuth\OAuthKeys;
use App\Core\Rbac\Scope;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\Bridge\User as OAuthUser;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * API-02 (OAuth), MKT-02 (docs/adr/024-marketplace.md): the consent
 * screen of the authorization code flow, as an API for the web app (the
 * app sends the user to `/oauth/authorize` on the web app, which calls
 * these).
 *
 * - GET checks the request (client, redirect URI, scopes, PKCE S256) with
 *   Passport's authorization server and answers what the app asks for, in
 *   plain words, with any existing install of it.
 * - POST approves (installing the app, or recording a new consent, as the
 *   chosen role and scope) or denies it, and answers where to send the
 *   browser: the app's redirect URI with a code, or with `access_denied`.
 *
 * An invalid client or redirect URI is never redirected to (RFC 6749 4.1.2.1).
 */
class AuthorizeController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly Installations $installations,
        private readonly MarketplacePresenter $presenter,
        private readonly Subscriptions $subscriptions,
    ) {}

    public function show(AuthorizeAppRequest $request): JsonResponse
    {
        [$app] = $this->validated($request);
        $installation = $this->installations->activeFor($app)?->load(['app.listedVersion', 'version', 'installer']);
        $user = $request->user();

        return new JsonResponse(['data' => [
            'app' => $this->presenter->detail($app, $this->subscriptions->current()?->currency, $installation),
            'can_install' => $user->can(Installations::INSTALL, Scope::tenant()),
            'can_pay' => $user->can('core.subscription.manage', Scope::tenant()),
        ]]);
    }

    public function decide(DecideAuthorizationRequest $request): JsonResponse
    {
        [$app, $authRequest] = $this->validated($request);

        if (! $request->approves()) {
            $authRequest->setUser(new OAuthUser($request->user()->id));
            $authRequest->setAuthorizationApproved(false);

            return $this->redirect(fn () => $this->server->completeAuthorizationRequest($authRequest, new PsrResponse));
        }

        $installation = $this->installations->consent($request->user(), $app, (string) $request->validated('role_id'), (string) $request->validated('scope_type'), $request->validated('scope_id'));
        // The code is issued to the install's principal: tokens act as the app, never as the person who approved.
        $authRequest->setUser(new OAuthUser($installation->principal_id));
        $authRequest->setAuthorizationApproved(true);

        return $this->redirect(fn () => $this->server->completeAuthorizationRequest($authRequest, new PsrResponse), $installation->id);
    }

    /** @return array{0: MarketplaceApp, 1: AuthorizationRequestInterface} */
    private function validated(AuthorizeAppRequest $request): array
    {
        OAuthKeys::assertConfigured();
        $parameters = $request->oauthParameters();
        $client = OAuthClient::query()->find($parameters['client_id']);
        $app = $client?->app_id === null ? null : MarketplaceApp::query()->with(['listedVersion', 'developer', 'client'])->find($client->app_id);

        if ($app === null || $app->listedVersion === null) {
            throw new ApiException(400, 'oauth_invalid_client', __('marketplace.errors.invalid_client'));
        }

        // No scope asked for: everything the listed version asks for.
        $parameters['scope'] ??= implode(' ', $app->listedVersion->scopeList());

        try {
            $authRequest = $this->server->validateAuthorizationRequest((new PsrHttpFactory)->createRequest($request)->withQueryParams($parameters));
        } catch (OAuthServerException $e) {
            throw new ApiException(400, 'oauth_'.$e->getErrorType(), __('marketplace.errors.invalid_request'), [], ['hint' => $e->getHint()]);
        }

        return [$app, $authRequest];
    }

    private function redirect(callable $complete, ?string $installationId = null): JsonResponse
    {
        try {
            $response = $complete();
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(new PsrResponse);
        }

        $location = $response->getHeaderLine('Location');

        if ($location === '') {
            throw new ApiException(400, 'oauth_invalid_request', __('marketplace.errors.invalid_request'));
        }

        return (new JsonResponse(['data' => array_filter(['redirect_to' => $location, 'installation_id' => $installationId])]))->header('Cache-Control', 'no-store');
    }
}
