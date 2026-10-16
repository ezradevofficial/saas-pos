<?php

namespace App\Core\Api\Http\Middleware;

use App\Core\Api\ApiKeyContext;
use App\Core\Api\ApiRateLimits;
use App\Core\Api\ApiScopes;
use App\Core\Api\ApiUsage;
use App\Core\Api\Idempotency;
use App\Core\Http\ApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `public-api:<module.resource>` marks a route of the public API (API-01,
 * docs/adr/020). Only marked routes accept API keys (ApiKeyAuthenticator),
 * and only marked routes are documented (OpenAPI, API-04).
 *
 * For a key's request, in order:
 * 1. the key's scopes must cover the resource: read for GET and HEAD,
 *    write otherwise (403 `api_scope_missing`);
 * 2. the request is counted (usage per day, last use) and rate-limited per
 *    key and per tenant (API-03; RateLimit-* headers, 429 `rate_limited`);
 * 3. a POST with `Idempotency-Key` runs once (Idempotency).
 *
 * People's requests pass through unchanged. Every response of a
 * deprecated operation (`api.deprecations`) carries Deprecation, Sunset
 * and Link headers, whoever calls it.
 */
class PublicApi
{
    public const ALIAS = 'public-api';

    public function __construct(
        private readonly ApiKeyContext $context,
        private readonly ApiRateLimits $limits,
        private readonly ApiUsage $usage,
        private readonly Idempotency $idempotency,
    ) {}

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $key = $this->context->key();

        if ($key === null) {
            return $this->deprecation($request, $next($request));
        }

        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (! ApiScopes::allows($key->scopeList(), $resource, $write)) {
            $scope = $resource.'.'.($write ? ApiScopes::WRITE : ApiScopes::READ);

            throw new ApiException(403, 'api_scope_missing', __('api.errors.scope_missing', ['scope' => $scope]), extra: ['scope' => $scope]);
        }

        $this->usage->record($key, $request->ip());
        $window = $this->limits->hit($key);

        $idempotencyKey = $request->headers->get(Idempotency::HEADER);
        $response = $request->isMethod('POST') && $idempotencyKey !== null
            ? $this->idempotency->handle($request, $key, $idempotencyKey, fn () => $next($request))
            : $next($request);

        $response->headers->set('RateLimit-Limit', (string) $window['limit']);
        $response->headers->set('RateLimit-Remaining', (string) $window['remaining']);
        $response->headers->set('RateLimit-Reset', (string) $window['reset']);

        return $this->deprecation($request, $response);
    }

    /** RFC 9745 Deprecation, RFC 8594 Sunset and the changelog link of a deprecated operation. */
    private function deprecation(Request $request, Response $response): Response
    {
        $route = $request->route();
        $uri = $route === null ? '' : preg_replace('#^api/v1/#', '', $route->uri());
        $entry = config('api.deprecations')[$request->method().' '.$uri] ?? null;

        if (! is_array($entry)) {
            return $response;
        }

        $response->headers->set('Deprecation', '@'.CarbonImmutable::parse($entry['deprecated_at'])->getTimestamp());

        if (isset($entry['sunset'])) {
            $response->headers->set('Sunset', CarbonImmutable::parse($entry['sunset'])->utc()->format('D, d M Y H:i:s').' GMT');
        }

        $link = rtrim((string) config('api.changelog_url'), '/').(isset($entry['anchor']) ? '#'.$entry['anchor'] : '');
        $response->headers->set('Link', "<{$link}>; rel=\"deprecation\"; type=\"text/html\"", false);

        return $response;
    }
}
